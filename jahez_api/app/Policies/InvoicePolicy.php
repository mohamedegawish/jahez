<?php

namespace App\Policies;

use App\Billing\BillingPolicy;
use App\Enums\InvoiceIssuer;
use App\Enums\Permission;
use App\Models\Agreement;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Invoices and their payments (ADR-017). The agreement's two parties see them; IMC
 * administrators with invoices.view_any see every invoice (PROPOSED billing oversight).
 * The issuer the invoicing policy names (OQ-16, ADR-023) drafts, edits, issues and cancels; the factory, the
 * billed party in every money flow the source mentions, pays. Anyone else is told the
 * invoice does not exist.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::InvoicesViewAny)
            || $user->factory_id !== null
            || $user->service_provider_id !== null;
    }

    public function view(User $user, Invoice $invoice): Response
    {
        return $this->canSee($user, $invoice) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Draft an invoice for an agreement: the issuer the invoicing policy in effect names
     * (ADR-023). While no invoicing policy applies, anyone who can see the agreement passes
     * here and the controller refuses the operation as not configured.
     */
    public function create(User $user, Agreement $agreement): Response
    {
        $canSeeAgreement = $agreement->sideOf($user) !== null
            || $user->hasPermission(Permission::InvoicesViewAny)
            || $user->hasPermission(Permission::AgreementsViewAny);

        if (! $canSeeAgreement) {
            return Response::denyAsNotFound();
        }

        $issuer = BillingPolicy::issuerFor($agreement);

        return $issuer === null || $this->isIssuer($user, $issuer, $agreement) ? Response::allow() : Response::deny();
    }

    /**
     * Edit, issue or cancel: the invoice's issuer.
     */
    public function manage(User $user, Invoice $invoice): Response
    {
        if ($invoice->agreement !== null && $this->isIssuer($user, $invoice->issuer, $invoice->agreement)) {
            return Response::allow();
        }

        return $this->canSee($user, $invoice) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Start a payment: the factory that agreed.
     */
    public function pay(User $user, Invoice $invoice): Response
    {
        if ($invoice->agreement !== null && $user->belongsToFactory($invoice->agreement->factory_id)) {
            return Response::allow();
        }

        return $this->canSee($user, $invoice) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Record money received outside a gateway (ADR-023): an IMC administrator granted
     * payments.record who can see every invoice.
     */
    public function recordPayment(User $user, Invoice $invoice): Response
    {
        if ($user->hasPermission(Permission::PaymentsRecord) && $user->hasPermission(Permission::InvoicesViewAny)) {
            return Response::allow();
        }

        return $this->canSee($user, $invoice) ? Response::deny() : Response::denyAsNotFound();
    }

    private function isIssuer(User $user, InvoiceIssuer $issuer, Agreement $agreement): bool
    {
        return match ($issuer) {
            InvoiceIssuer::Imc => $user->hasPermission(Permission::InvoicesManage),
            InvoiceIssuer::ServiceProvider => $user->belongsToServiceProvider($agreement->service_provider_id),
        };
    }

    private function canSee(User $user, Invoice $invoice): bool
    {
        return $invoice->agreement?->sideOf($user) !== null || $user->hasPermission(Permission::InvoicesViewAny);
    }
}
