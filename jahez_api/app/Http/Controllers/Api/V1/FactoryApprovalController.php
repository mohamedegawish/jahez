<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\FactoryApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApproveFactoryRequest;
use App\Http\Requests\Api\V1\RequestFactoryReviewRequest;
use App\Http\Resources\V1\FactoryResource;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * IMC review of a factory's account and profile (ADR-021), separate from its readiness
 * classification: nothing here reads or writes an assessment, a score or a category.
 */
class FactoryApprovalController extends Controller
{
    /**
     * Record an IMC decision. The factory row is locked, so two administrators deciding
     * at once are applied one after the other, and a transition the current status does
     * not allow is refused with 409. Approval needs the configured onboarding fields
     * (OQ-19), as provider approval needs the provider's (OQ-36).
     */
    public function decide(ApproveFactoryRequest $request, Factory $factory, #[CurrentUser] User $actor): FactoryResource
    {
        $decision = $request->decision();

        DB::transaction(function () use ($request, $factory, $actor, $decision): void {
            $locked = Factory::query()->lockForUpdate()->findOrFail($factory->id);
            $previousStatus = $locked->approval_status;

            if (! $previousStatus->canBecome($decision)) {
                throw new ConflictHttpException("A factory that is {$previousStatus->value} cannot become {$decision->value}.");
            }

            $missingFields = $decision === FactoryApprovalStatus::Approved ? $locked->missingProfileFields() : [];
            if ($missingFields !== []) {
                throw ValidationException::withMessages([
                    'decision' => 'The factory profile is missing required fields: '.implode(', ', $missingFields).'.',
                ]);
            }

            $locked->approval_status = $decision;
            $locked->approval_reason = $request->reason();
            $locked->approval_changed_at = now();
            $locked->save();

            AuditLog::record(AuditEvent::FactoryApprovalChanged, $actor, $locked, [
                'from' => $previousStatus->value,
                'to' => $decision->value,
                'reason' => $request->reason(),
            ]);
            MarketplaceNotifications::factoryApprovalChanged($locked);
        });

        return new FactoryResource($factory->refresh()->load(FactoryController::RELATIONS));
    }

    /**
     * A factory that was rejected or asked for corrections, after updating its profile,
     * asks IMC to review it again: it returns to `pending`. Any other status gets 409.
     */
    public function requestReview(RequestFactoryReviewRequest $request, Factory $factory, #[CurrentUser] User $actor): FactoryResource
    {
        DB::transaction(function () use ($request, $factory, $actor): void {
            $locked = Factory::query()->lockForUpdate()->findOrFail($factory->id);
            $previousStatus = $locked->approval_status;

            if (! $previousStatus->canRequestReview()) {
                throw new ConflictHttpException("A factory that is {$previousStatus->value} cannot ask for a new review.");
            }

            $missingFields = $locked->missingProfileFields();
            if ($missingFields !== []) {
                throw ValidationException::withMessages([
                    'profile' => 'Complete these profile fields before asking for a review: '.implode(', ', $missingFields).'.',
                ]);
            }

            $locked->approval_status = FactoryApprovalStatus::Pending;
            $locked->approval_reason = null;
            $locked->approval_changed_at = now();
            $locked->save();

            AuditLog::record(AuditEvent::FactoryReviewRequested, $actor, $locked, [
                'from' => $previousStatus->value,
                'to' => FactoryApprovalStatus::Pending->value,
                'note' => $request->note(),
            ]);
            MarketplaceNotifications::reviewRequested('factory', $locked->id, $locked->name);
        });

        return new FactoryResource($factory->refresh()->load(FactoryController::RELATIONS));
    }
}
