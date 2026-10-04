<?php

namespace App\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An operation needs a business rule that is not decided, or not approved, for the
 * record (ADR-017, ADR-023). Rendered as 409 with the code `policy_not_configured`, the
 * open question that decides it (`decision_needed`) and, when known, an Arabic
 * explanation (`reason_ar`) and the policy kinds that are missing (`missing_policies`).
 */
class PolicyNotConfiguredException extends HttpException
{
    /**
     * @param  list<string>  $missingPolicies
     */
    public function __construct(
        string $message,
        public readonly string $decisionNeeded,
        public readonly ?string $reasonAr = null,
        public readonly array $missingPolicies = [],
    ) {
        parent::__construct(409, $message);
    }
}
