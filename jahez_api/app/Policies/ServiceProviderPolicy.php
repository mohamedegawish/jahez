<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * IMC administrators manage every provider; a provider member may see only their own.
 * A provider the user may not see is reported as not found so its existence is not disclosed.
 */
class ServiceProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ServiceProvidersViewAny);
    }

    public function view(User $user, ServiceProvider $serviceProvider): Response
    {
        return $user->hasPermission(Permission::ServiceProvidersViewAny) || $user->serviceProvider()->is($serviceProvider)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ServiceProvidersCreate);
    }

    /**
     * IMC administrators update any provider; a provider's own members update its
     * workbook profile and offered services (owner decision 2026-10-03), never its approval.
     */
    public function update(User $user, ServiceProvider $serviceProvider): Response
    {
        return $user->hasPermission(Permission::ServiceProvidersUpdate) || $user->serviceProvider()->is($serviceProvider)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Approve, reject or suspend a provider: IMC only (ADR-014).
     */
    public function approve(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->hasPermission(Permission::ServiceProvidersApprove)) {
            return Response::allow();
        }

        return $user->serviceProvider()->is($serviceProvider)
            ? Response::deny()
            : Response::denyAsNotFound();
    }

    /**
     * Approve, reject or suspend one of the provider's service listings: IMC only
     * (ADR-021). The provider's own members see the decision but may not take it.
     */
    public function reviewListing(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->hasPermission(Permission::ServiceListingsReview)) {
            return Response::allow();
        }

        return $user->serviceProvider()->is($serviceProvider)
            ? Response::deny()
            : Response::denyAsNotFound();
    }

    /**
     * Send a rejected listing back to IMC review: the provider's own members (ADR-022).
     * IMC administrators decide through the review action instead.
     */
    public function resubmitListing(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->serviceProvider()->is($serviceProvider)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::ServiceProvidersViewAny) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Replace the packages and prices of one of the provider's listings (ADR-027): the
     * provider's own members. The change sends the listing back to IMC review; IMC
     * administrators review it but never write a provider's prices.
     */
    public function updateListingPackages(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->serviceProvider()->is($serviceProvider)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::ServiceProvidersViewAny) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Ask IMC to review the provider again after a rejection: the provider's own members
     * (PROPOSED). IMC administrators decide through the approval action instead.
     */
    public function requestReview(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->serviceProvider()->is($serviceProvider)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::ServiceProvidersViewAny) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Ask IMC to change verified legal information, or cancel that request: the
     * provider's own members (ADR-019). IMC administrators edit the fields directly.
     */
    public function requestLegalChange(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->serviceProvider()->is($serviceProvider)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::ServiceProvidersViewAny) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * The queue of change requests waiting for review: IMC administrators who decide
     * approvals.
     */
    public function reviewChangeRequests(User $user): bool
    {
        return $user->hasPermission(Permission::ServiceProvidersApprove);
    }

    /**
     * Record or read DOC §6 evaluations: IMC only. Evaluations are internal IMC
     * assessments, so the provider's own members get 403 (PROPOSED, OQ-13).
     */
    public function evaluate(User $user, ServiceProvider $serviceProvider): Response
    {
        if ($user->hasPermission(Permission::ServiceProvidersEvaluate)) {
            return Response::allow();
        }

        return $user->serviceProvider()->is($serviceProvider)
            ? Response::deny()
            : Response::denyAsNotFound();
    }

    /**
     * Browse the provider directory: factory members (for their own factory) and IMC
     * administrators. Provider members may not browse their competitors.
     */
    public function viewDirectory(User $user): bool
    {
        return $user->hasPermission(Permission::ServiceProvidersViewAny) || $user->factory_id !== null;
    }
}
