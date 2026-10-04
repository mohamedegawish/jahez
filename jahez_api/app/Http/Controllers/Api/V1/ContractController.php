<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PolicyCalendar;
use App\Billing\PolicyContext;
use App\Billing\PolicyResolver;
use App\Enums\AuditEvent;
use App\Enums\ContractStatus;
use App\Enums\FinancialPolicyKind;
use App\Enums\PolicyBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelContractRequest;
use App\Http\Requests\Api\V1\ListContractsRequest;
use App\Http\Requests\Api\V1\StoreContractRequest;
use App\Http\Resources\V1\ContractResource;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Contract drafts (ADR-017). Under the OQ-17 interim a contract is a draft and never
 * binding: there is no signing, approval or execution step until the owner decides the
 * parties, templates and e-signature. A party drafts it from an agreement; only one
 * draft is in force at a time, and a cancelled draft is kept, so the versions form the
 * contract's history.
 */
class ContractController extends Controller
{
    private const RELATIONS = ['agreement', 'draftedBy'];

    public function index(ListContractsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $contracts = Contract::query()
            ->when($user->factory_id !== null, fn (Builder $query) => $query->whereHas('agreement', fn (Builder $agreements) => $agreements->where('factory_id', $user->factory_id)))
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->whereHas('agreement', fn (Builder $agreements) => $agreements->where('service_provider_id', $user->service_provider_id)))
            ->when($request->input('filter.status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->input('filter.agreement'), fn (Builder $query, int|string $id) => $query->where('agreement_id', (int) $id))
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ContractResource::collection($contracts);
    }

    public function show(Contract $contract): ContractResource
    {
        Gate::authorize('view', $contract);

        return new ContractResource($contract->load(self::RELATIONS));
    }

    /**
     * Draft the next contract version for an agreement. The agreement row is locked, so
     * two parties drafting at once get one draft and one 409. The draft stores a snapshot
     * of the agreed terms and, when an approved contract template applies, the template
     * version and its clauses (ADR-023); later template changes never alter it. Without a
     * template the draft is generated as before. Either way it stays draft_not_binding.
     */
    public function store(StoreContractRequest $request, Agreement $agreement, #[CurrentUser] User $user): JsonResponse
    {
        $contract = DB::transaction(function () use ($request, $agreement, $user): Contract {
            $locked = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $locked->ensureApprovedByImc();
            $active = $locked->activeContract()->first();

            if ($active !== null) {
                throw new ConflictHttpException("This agreement already has a contract draft (version {$active->version}); cancel it before drafting a new version.");
            }

            $template = PolicyResolver::resolve(FinancialPolicyKind::ContractTemplate, PolicyContext::forAgreement($locked), PolicyCalendar::today(), lock: true);
            $minimumTrainees = (int) ($template->parameters['knowledge_transfer_min_trainees'] ?? Contract::MIN_KNOWLEDGE_TRANSFER_TRAINEES);
            if ($request->integer('knowledge_transfer.trainees') < $minimumTrainees) {
                throw ValidationException::withMessages(['knowledge_transfer.trainees' => "The contract template in effect requires at least {$minimumTrainees} IMC engineers to be trained."]);
            }

            $contract = new Contract;
            $contract->agreement_id = $locked->id;
            $contract->version = (int) $locked->contracts()->max('version') + 1;
            $contract->knowledge_transfer_trainees = $request->integer('knowledge_transfer.trainees');
            $contract->knowledge_transfer_plan = $request->string('knowledge_transfer.training_plan')->toString();
            $contract->notes = $request->filled('notes') ? $request->string('notes')->toString() : null;
            $contract->drafted_by_user_id = $user->id;
            $contract->policy_basis = PolicyBasis::Policy;
            $contract->contract_template_version_id = $template?->id;
            $contract->terms_snapshot = Contract::snapshotTerms($locked, $template);
            $contract->save();

            AuditLog::record(AuditEvent::ContractDrafted, $user, $contract, array_filter([
                'agreement_id' => $locked->id,
                'version' => $contract->version,
                'contract_template_version_id' => $template?->id,
            ], fn (?int $value): bool => $value !== null));
            MarketplaceNotifications::contractChanged($locked, $contract, (string) $locked->sideOf($user), cancelled: false);

            return $contract;
        });

        return (new ContractResource($contract->refresh()->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function cancel(CancelContractRequest $request, Contract $contract, #[CurrentUser] User $user): ContractResource
    {
        DB::transaction(function () use ($request, $contract, $user): void {
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $locked->moveTo(ContractStatus::Cancelled, $request->reason(), $user);

            AuditLog::record(AuditEvent::ContractCancelled, $user, $locked, [
                'agreement_id' => $locked->agreement_id,
                'version' => $locked->version,
                'reason' => $request->reason(),
            ]);

            $agreement = Agreement::query()->findOrFail($locked->agreement_id);
            MarketplaceNotifications::contractChanged($agreement, $locked, (string) $agreement->sideOf($user), cancelled: true);
        });

        return new ContractResource($contract->refresh()->load(self::RELATIONS));
    }
}
