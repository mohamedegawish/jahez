<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A request as sent to one provider, and its private negotiation (ADR-015). Only the
 * requesting factory and that provider are parties. IMC administrators may see that a
 * thread exists and its status, never its messages or offers (PROPOSED, OQ-39). Anyone
 * else, including competing providers, is told the thread does not exist.
 */
class ProviderRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ServiceRequestsViewAny)
            || $user->factory_id !== null
            || $user->service_provider_id !== null;
    }

    public function view(User $user, ProviderRequest $providerRequest): Response
    {
        return $this->canSee($user, $providerRequest) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Accept or decline the request: the provider it was sent to.
     */
    public function respond(User $user, ProviderRequest $providerRequest): Response
    {
        return $this->allowSide($user, $providerRequest, ProviderRequestMessage::SIDE_PROVIDER);
    }

    /**
     * Withdraw the request from this provider: the requesting factory.
     */
    public function withdraw(User $user, ProviderRequest $providerRequest): Response
    {
        return $this->allowSide($user, $providerRequest, ProviderRequestMessage::SIDE_FACTORY);
    }

    /**
     * Read the messages and offers, and write messages: both parties, nobody else.
     */
    public function negotiate(User $user, ProviderRequest $providerRequest): Response
    {
        if ($providerRequest->sideOf($user) !== null) {
            return Response::allow();
        }

        return $this->canSee($user, $providerRequest) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Submit an offer version: the provider.
     */
    public function submitOffer(User $user, ProviderRequest $providerRequest): Response
    {
        return $this->allowSide($user, $providerRequest, ProviderRequestMessage::SIDE_PROVIDER);
    }

    /**
     * Accept an offer: the requesting factory.
     */
    public function acceptOffer(User $user, ProviderRequest $providerRequest): Response
    {
        return $this->allowSide($user, $providerRequest, ProviderRequestMessage::SIDE_FACTORY);
    }

    private function canSee(User $user, ProviderRequest $providerRequest): bool
    {
        return $user->hasPermission(Permission::ServiceRequestsViewAny) || $providerRequest->sideOf($user) !== null;
    }

    /**
     * Allows the given side; refuses anyone else who can see the thread with 403, and
     * everyone else with 404.
     */
    private function allowSide(User $user, ProviderRequest $providerRequest, string $side): Response
    {
        if ($providerRequest->sideOf($user) === $side) {
            return Response::allow();
        }

        return $this->canSee($user, $providerRequest) ? Response::deny() : Response::denyAsNotFound();
    }
}
