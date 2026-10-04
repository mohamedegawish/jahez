<?php

namespace App\Enums;

/**
 * The state of an uploaded organization document (ADR-019).
 *
 * - Active: the organization's current file of that type.
 * - PendingReview: attached to a change request IMC has not decided yet.
 * - Superseded: replaced by a newer file; kept for the record.
 * - Rejected: its change request was rejected or cancelled; kept for the record.
 */
enum DocumentStatus: string
{
    case Active = 'active';
    case PendingReview = 'pending_review';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
}
