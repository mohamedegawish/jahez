<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\TransformationPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListTransformationPlansRequest;
use App\Http\Requests\Api\V1\StoreTransformationPlanRequest;
use App\Http\Requests\Api\V1\TransformationPlanActionRequest;
use App\Http\Resources\V1\TransformationPlanResource;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\TransformationPlan;
use App\Models\User;
use App\Notifications\PlatformNotifier;
use App\TransformationPlans\TransformationPlanDraft;
use App\TransformationPlans\TransformationPlanPresenter;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Factory transformation plans (ADR-025). IMC creates a plan for a factory, drafts and
 * publishes its versions (TransformationPlanVersionController), records the execution of
 * its items (TransformationPlanItemController), and suspends, resumes or closes it. A
 * factory's members read their published plan; nobody else sees it.
 */
class TransformationPlanController extends Controller
{
    /**
     * The relations every plan response reads.
     */
    public const RELATIONS = ['industrialFactory', 'publishedVersion', 'draftVersion', 'basedOnAssessment.category'];

    public function __construct(private readonly TransformationPlanDraft $draft) {}

    /**
     * IMC administrators see every plan (filters: status, factory, search on the factory
     * name); a factory member sees its own factory's plans once published.
     */
    public function index(ListTransformationPlansRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $forImc = $user->hasPermission(Permission::TransformationPlansViewAny);

        $plans = TransformationPlan::query()
            ->when(! $forImc, fn (Builder $query) => $query
                ->where('factory_id', (int) $user->factory_id)
                ->where('status', '!=', TransformationPlanStatus::Draft->value))
            ->when($forImc ? $request->input('filter.status') : null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($forImc ? $request->input('filter.factory') : null, fn (Builder $query) => $query->where('factory_id', $request->integer('filter.factory')))
            ->when($forImc ? $request->input('search') : null, fn (Builder $query, string $term) => $query->whereHas(
                'industrialFactory',
                fn (Builder $factories) => $factories->nameContains($term),
            ))
            ->when(
                $request->sortColumnAndDirection(),
                fn (Builder $query, array $sort) => $query->orderBy($sort[0], $sort[1]),
                fn (Builder $query) => $query->orderByDesc('id'),
            )
            ->orderByDesc('id')
            ->with(self::RELATIONS)
            ->paginate($request->perPage())
            ->withQueryString();

        $progress = TransformationPlanPresenter::progressOfVersions(
            array_values(array_filter($plans->getCollection()->map(fn (TransformationPlan $plan): ?int => $plan->publishedVersion?->id)->all()))
        );

        $plans->setCollection($plans->getCollection()->map(
            fn (TransformationPlan $plan): TransformationPlanResource => (new TransformationPlanResource($plan))
                ->withProgress($plan->publishedVersion === null ? null : ($progress[$plan->publishedVersion->id] ?? null))
        ));

        return TransformationPlanResource::collection($plans);
    }

    /**
     * Create the factory's plan with an empty first draft (409 when the factory has no
     * readiness assessment or already has an open plan).
     */
    public function store(StoreTransformationPlanRequest $request, Factory $factory, #[CurrentUser] User $user): JsonResponse
    {
        $plan = $this->draft->create(
            $factory,
            $user,
            $request->string('title')->toString(),
            $request->filled('summary_ar') ? $request->string('summary_ar')->toString() : null,
        );

        return self::detailed($plan)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(TransformationPlan $transformationPlan): TransformationPlanResource
    {
        Gate::authorize('view', $transformationPlan);

        return self::detailed($transformationPlan);
    }

    /**
     * Delete a plan that was never published (409 otherwise: the factory has seen it).
     */
    public function destroy(TransformationPlan $transformationPlan, #[CurrentUser] User $user): Response
    {
        Gate::authorize('manage', $transformationPlan);

        $this->draft->delete($transformationPlan, $user);

        return response()->noContent();
    }

    /**
     * Pause the plan: the factory still sees it, but no item starts and no request is sent
     * for its items.
     */
    public function suspend(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->move($transformationPlan, TransformationPlanStatus::Suspended, $user, $request->reason());
    }

    public function resume(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->move($transformationPlan, TransformationPlanStatus::Published, $user, $request->reason(), TransformationPlanStatus::Suspended);
    }

    /**
     * End the plan. It stays readable; the factory may then receive a new plan.
     */
    public function close(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->move($transformationPlan, TransformationPlanStatus::Closed, $user, $request->reason());
    }

    /**
     * The detailed response for the signed-in reader.
     */
    public static function detailed(TransformationPlan $plan): TransformationPlanResource
    {
        return (new TransformationPlanResource($plan->refresh()->load(self::RELATIONS)))->detailed();
    }

    private function move(TransformationPlan $plan, TransformationPlanStatus $next, User $user, ?string $reason, ?TransformationPlanStatus $requiredFrom = null): TransformationPlanResource
    {
        DB::transaction(function () use ($plan, $next, $user, $reason, $requiredFrom): void {
            $locked = TransformationPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $from = $locked->status;

            if ($requiredFrom !== null && $from !== $requiredFrom) {
                throw new ConflictHttpException("This transformation plan is {$from->value}; only a {$requiredFrom->value} plan can do this.");
            }

            $locked->moveTo($next, $reason);

            AuditLog::record(AuditEvent::TransformationPlanStatusChanged, $user, $locked, [
                'from' => $from->value,
                'to' => $next->value,
                'reason' => $reason,
            ]);

            PlatformNotifier::factoryMembers(
                $locked->factory_id,
                NotificationEvent::TransformationPlanStatusChanged,
                "transformation_plan.{$locked->id}.{$next->value}.".now()->getTimestampMs(),
                match ($next) {
                    TransformationPlanStatus::Suspended => 'أوقف مركز تحديث الصناعة خطة التحول الرقمي لمنشأتكم مؤقتًا.',
                    TransformationPlanStatus::Closed => 'أغلق مركز تحديث الصناعة خطة التحول الرقمي لمنشأتكم.',
                    default => 'استأنف مركز تحديث الصناعة خطة التحول الرقمي لمنشأتكم.',
                },
                '/factory/roadmap',
                ['type' => 'transformation_plan', 'id' => $locked->id],
            );
        });

        return self::detailed($plan);
    }
}
