<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Factory service requests (ADR-015). The requesting factory's members own them; each
 * provider a request was sent to may see the shared request content (and only its own
 * thread, which the controller enforces); IMC administrators may look on (PROPOSED,
 * OQ-39). Anyone else is told the request does not exist.
 */
class ServiceRequestPolicy
{
    /**
     * List requests: IMC administrators (all) and factory members (their factory's).
     * Providers use the provider-request list instead.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ServiceRequestsViewAny) || $user->factory_id !== null;
    }

    public function view(User $user, ServiceRequest $serviceRequest): Response
    {
        $isRecipient = $user->service_provider_id !== null
            && $serviceRequest->providerRequests()->where('service_provider_id', $user->service_provider_id)->exists();

        return $user->hasPermission(Permission::ServiceRequestsViewAny) || $user->belongsToFactory($serviceRequest->factory_id) || $isRecipient
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Only a factory member can ask for a service: the marketplace is factory-initiated.
     */
    public function create(User $user): bool
    {
        return $user->factory_id !== null;
    }

    /**
     * Send the open request to more providers: the requesting factory (PROPOSED, OQ-38).
     */
    public function addProviders(User $user, ServiceRequest $serviceRequest): Response
    {
        return $this->cancel($user, $serviceRequest);
    }

    public function cancel(User $user, ServiceRequest $serviceRequest): Response
    {
        if ($user->belongsToFactory($serviceRequest->factory_id)) {
            return Response::allow();
        }

        return $this->view($user, $serviceRequest)->allowed() ? Response::deny() : Response::denyAsNotFound();
    }
}
