<?php

namespace App\Enums;

/**
 * What a plan item's card shows (ADR-025): its recorded execution status, refined for an
 * item that has not started by its prerequisites and by the service request and
 * agreement linked to it. Computed by the server on every read, never stored.
 */
enum PlanItemState: string
{
    /** Not started; an item it depends on is not completed yet. */
    case WaitingPrerequisites = 'waiting_prerequisites';

    /** Not started; prerequisites done, but no linked request has reached an agreement. */
    case NotStarted = 'not_started';

    /** Not started; the linked request's agreement waits for IMC review. */
    case AwaitingApproval = 'awaiting_approval';

    /** Prerequisites done and the agreement approved: IMC may record the start. */
    case Ready = 'ready';

    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
