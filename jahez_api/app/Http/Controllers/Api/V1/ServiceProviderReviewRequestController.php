<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ProviderApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestProviderReviewRequest;
use App\Http\Resources\V1\ServiceProviderResource;
use App\Models\AuditLog;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ServiceProviderReviewRequestController extends Controller
{
    /**
     * A rejected provider, or one IMC asked for corrections (ADR-021), after updating its
     * profile, asks IMC to review it again (PROPOSED, ADR-014 addendum): it returns to
     * `pending`, in IMC's review queue, and IMC reviewers are notified. The
     * row is locked like an approval decision, and any other status gets 409.
     */
    public function __invoke(RequestProviderReviewRequest $request, ServiceProvider $serviceProvider, #[CurrentUser] User $actor): ServiceProviderResource
    {
        DB::transaction(function () use ($request, $serviceProvider, $actor): void {
            $locked = ServiceProvider::query()->lockForUpdate()->findOrFail($serviceProvider->id);
            $previousStatus = $locked->approval_status;

            if (! $previousStatus->canRequestReview()) {
                throw new ConflictHttpException("A provider that is {$previousStatus->value} cannot ask for a new review; only a rejected provider or one asked for corrections can.");
            }

            $missingFields = $locked->missingRequiredProfileFields();
            if ($missingFields !== []) {
                throw ValidationException::withMessages([
                    'profile' => 'Complete these profile fields before asking for a review: '.implode(', ', $missingFields).'.',
                ]);
            }

            $locked->approval_status = ProviderApprovalStatus::Pending;
            $locked->approval_reason = null;
            $locked->approval_changed_at = now();
            $locked->save();

            AuditLog::record(AuditEvent::ServiceProviderReviewRequested, $actor, $locked, [
                'from' => $previousStatus->value,
                'to' => ProviderApprovalStatus::Pending->value,
                'note' => $request->note(),
            ]);
            MarketplaceNotifications::reviewRequested('service_provider', $locked->id, $locked->name);
        });

        return new ServiceProviderResource($serviceProvider->refresh()->load(ServiceProviderController::PROFILE_RELATIONS));
    }
}
