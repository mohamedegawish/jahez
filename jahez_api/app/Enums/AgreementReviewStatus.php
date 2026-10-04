<?php

namespace App\Enums;

/**
 * IMC's review of an agreement (ADR-020). `pending` is the absence of a decision; a
 * decision is final. Only an approved agreement may get a contract draft or an invoice.
 */
enum AgreementReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
