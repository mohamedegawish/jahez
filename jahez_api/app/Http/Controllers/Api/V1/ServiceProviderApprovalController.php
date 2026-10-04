<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ProviderApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApproveServiceProviderRequest;
use App\Http\Resources\V1\ServiceProviderResource;
use App\Models\AuditLog;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceProviderApprovalController extends Controller
{
    /**
     * Record an IMC approval decision (ADR-014). The provider row is locked, so two
     * administrators deciding at once are applied one after the other, and a transition
     * the current status does not allow is refused with 409.
     */
    public function __invoke(ApproveServiceProviderRequest $request, ServiceProvider $serviceProvider, #[CurrentUser] User $actor): ServiceProviderResource
    {
        $decision = $request->decision();

        DB::transaction(function () use ($request, $serviceProvider, $actor, $decision): void {
            $locked = ServiceProvider::query()->lockForUpdate()->findOrFail($serviceProvider->id);
            $previousStatus = $locked->approval_status;

            if (! $previousStatus->canBecome($decision)) {
                abort(409, "A provider that is {$previousStatus->value} cannot become {$decision->value}.");
            }

            $missingFields = $decision === ProviderApprovalStatus::Approved ? $locked->missingRequiredProfileFields() : [];
            if ($missingFields !== []) {
                throw ValidationException::withMessages([
                    'decision' => 'The provider profile is missing required fields: '.implode(', ', $missingFields).'.',
                ]);
            }

            $locked->approval_status = $decision;
            $locked->approval_reason = $request->reason();
            $locked->approval_changed_at = now();
            $locked->save();

            AuditLog::record(AuditEvent::ServiceProviderApprovalChanged, $actor, $locked, [
                'from' => $previousStatus->value,
                'to' => $decision->value,
                'reason' => $request->reason(),
            ]);
            MarketplaceNotifications::providerApprovalChanged($locked);
        });

        return new ServiceProviderResource($serviceProvider->refresh()->load(ServiceProviderController::PROFILE_RELATIONS));
    }
}
