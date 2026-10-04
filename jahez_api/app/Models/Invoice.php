<?php

namespace App\Models;

use App\Billing\InvoiceCalculator;
use App\Billing\Money;
use App\Billing\PolicyCalendar;
use App\Billing\PolicyParameters;
use App\Enums\InvoiceIssuer;
use App\Enums\InvoiceStatus;
use App\Enums\PolicyBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An invoice for an agreement (ADR-017). A draft has lines and a subtotal; issuing fixes
 * the number, tax and total under the owner-configured rules (OQ-16) and freezes the
 * lines. Status changes only through moveTo(); `paid` and `refunded` follow verified
 * payment evidence only.
 *
 * @property int $id
 * @property int $agreement_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property InvoiceIssuer $issuer
 * @property string $currency
 * @property string $subtotal_amount
 * @property string|null $tax_rate_percent
 * @property string|null $tax_amount
 * @property string|null $total_amount
 * @property string|null $revenue_share_percent
 * @property string|null $revenue_share_amount
 * @property string $type
 * @property string|null $payer
 * @property PolicyBasis $policy_basis
 * @property int|null $invoicing_policy_version_id
 * @property int|null $tax_policy_version_id
 * @property int|null $payment_terms_policy_version_id
 * @property int|null $revenue_share_policy_version_id
 * @property string|null $fees_amount
 * @property string $amount_paid
 * @property CarbonImmutable|null $due_date
 * @property array<string, mixed>|null $calculation
 * @property int $created_by_user_id
 * @property int|null $issued_by_user_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $paid_at
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 */
class Invoice extends Model
{
    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'number' => null,
        'status' => 'draft',
        'subtotal_amount' => '0.00',
        'tax_rate_percent' => null,
        'tax_amount' => null,
        'total_amount' => null,
        'revenue_share_percent' => null,
        'revenue_share_amount' => null,
        'issued_by_user_id' => null,
        'issued_at' => null,
        'paid_at' => null,
        'status_reason' => null,
        'status_changed_at' => null,
        'type' => PolicyParameters::INVOICE_TYPE_AGREEMENT_SERVICE,
        'payer' => null,
        'policy_basis' => 'policy',
        'invoicing_policy_version_id' => null,
        'tax_policy_version_id' => null,
        'payment_terms_policy_version_id' => null,
        'revenue_share_policy_version_id' => null,
        'fees_amount' => null,
        'amount_paid' => '0.00',
        'due_date' => null,
        'calculation' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agreement_id' => 'integer',
            'status' => InvoiceStatus::class,
            'issuer' => InvoiceIssuer::class,
            'subtotal_amount' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'revenue_share_percent' => 'decimal:2',
            'revenue_share_amount' => 'decimal:2',
            'created_by_user_id' => 'integer',
            'issued_by_user_id' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'policy_basis' => PolicyBasis::class,
            'invoicing_policy_version_id' => 'integer',
            'tax_policy_version_id' => 'integer',
            'payment_terms_policy_version_id' => 'integer',
            'revenue_share_policy_version_id' => 'integer',
            'fees_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date' => 'immutable_date',
            'calculation' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * Add a line to a draft and update its subtotal. Call inside the transaction that
     * locked the invoice.
     */
    public function addLine(string $description, int $quantity, string $unitAmount): InvoiceLine
    {
        $lineMinor = $quantity * Money::toMinor($unitAmount);
        $subtotalMinor = Money::toMinor($this->subtotal_amount) + $lineMinor;

        if ($lineMinor > Money::MAX_MINOR || $subtotalMinor > Money::MAX_MINOR) {
            throw ValidationException::withMessages(['quantity' => 'The invoice total would exceed the largest amount the system can hold.']);
        }

        $line = new InvoiceLine;
        $line->invoice_id = $this->id;
        $line->position = (int) $this->lines()->max('position') + 1;
        $line->description = $description;
        $line->quantity = $quantity;
        $line->unit_amount = Money::fromMinor(Money::toMinor($unitAmount));
        $line->line_amount = Money::fromMinor($lineMinor);
        $line->save();

        $this->subtotal_amount = Money::fromMinor($subtotalMinor);
        $this->save();

        return $line;
    }

    /**
     * Remove a line from a draft and update its subtotal.
     */
    public function removeLine(InvoiceLine $line): void
    {
        $line->delete();
        $this->subtotal_amount = Money::fromMinor(
            $this->lines()->get()->sum(fn (InvoiceLine $remaining): int => Money::toMinor($remaining->line_amount))
        );
        $this->save();
    }

    /**
     * Issue the draft under the approved policies resolved for it (ADR-023): the next
     * number in the invoicing policy's format, the taxes, fees and total from the tax
     * policy, the due date from the payment terms, and the informational revenue share
     * when one applies. The policy versions and the whole calculation are stored with
     * the invoice and never recalculated. Call inside the transaction that locked the
     * invoice.
     *
     * @param  array{invoicing: FinancialPolicyVersion, tax: FinancialPolicyVersion, payment_terms: FinancialPolicyVersion, revenue_share: FinancialPolicyVersion|null}  $policies
     */
    public function issue(User $actor, array $policies): void
    {
        if (! $this->status->canBecome(InvoiceStatus::Issued)) {
            throw new ConflictHttpException("This invoice is {$this->status->value} and cannot be issued.");
        }

        $subtotalMinor = Money::toMinor($this->subtotal_amount);
        if ($subtotalMinor === 0) {
            throw ValidationException::withMessages(['lines' => 'An invoice needs at least one line with an amount before it is issued.']);
        }

        $calculation = InvoiceCalculator::calculate($subtotalMinor, $policies['tax']->parameters, $policies['revenue_share']?->parameters);
        $issuedOn = PolicyCalendar::today();
        $invoicing = $policies['invoicing']->parameters;

        $this->number = self::nextNumber((string) $invoicing['number_prefix'], (int) $invoicing['number_padding']);
        $this->payer = (string) $invoicing['payer'];
        $this->fees_amount = $calculation['fees_total'];
        $this->tax_rate_percent = $calculation['tax_rate_percent'];
        $this->tax_amount = $calculation['tax_total'];
        $this->total_amount = $calculation['total'];
        $this->revenue_share_percent = $calculation['revenue_share']['rate_percent'] ?? null;
        $this->revenue_share_amount = $calculation['revenue_share']['amount'] ?? null;
        $this->invoicing_policy_version_id = $policies['invoicing']->id;
        $this->tax_policy_version_id = $policies['tax']->id;
        $this->payment_terms_policy_version_id = $policies['payment_terms']->id;
        $this->revenue_share_policy_version_id = $policies['revenue_share']?->id;
        $this->due_date = InvoiceCalculator::dueDate($issuedOn, $policies['payment_terms']->parameters);
        $this->calculation = [
            ...$calculation,
            'issued_on' => $issuedOn->toDateString(),
            'payment_terms' => $policies['payment_terms']->parameters,
            'policy_versions' => array_map(
                fn (?FinancialPolicyVersion $version): ?array => $version?->reference(),
                $policies,
            ),
        ];
        $this->issued_by_user_id = $actor->id;
        $this->issued_at = now();
        $this->moveTo(InvoiceStatus::Issued);
    }

    /**
     * Apply verified money received (a gateway payment that succeeded, or an authorised
     * manual entry): the paid amount grows and the invoice becomes paid or partially paid.
     * Call inside the transaction that locked the invoice.
     */
    public function applyPayment(string $amount): InvoiceStatus
    {
        $paidMinor = Money::toMinor($this->amount_paid) + Money::toMinor($amount);
        $totalMinor = Money::toMinor((string) $this->total_amount);

        if ($paidMinor > $totalMinor) {
            throw new ConflictHttpException('The payment is larger than the amount outstanding on this invoice.');
        }

        $this->amount_paid = Money::fromMinor($paidMinor);
        $next = $paidMinor === $totalMinor ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;
        if ($next !== $this->status) {
            $this->moveTo($next);
        } else {
            $this->save();
        }

        return $next;
    }

    /**
     * What is still to be paid on an issued invoice, as a decimal string.
     */
    public function outstandingAmount(): string
    {
        return Money::fromMinor(max(0, Money::toMinor((string) ($this->total_amount ?? '0.00')) - Money::toMinor($this->amount_paid)));
    }

    /**
     * Unpaid, or partly paid, after its due date. Overdue is not stored: it follows from
     * the due date in the business calendar.
     */
    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return $this->due_date !== null
            && in_array($this->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)
            && $this->due_date->toDateString() < ($today ?? PolicyCalendar::today())->toDateString();
    }

    /**
     * @return BelongsTo<FinancialPolicyVersion, $this>
     */
    public function invoicingPolicyVersion(): BelongsTo
    {
        return $this->belongsTo(FinancialPolicyVersion::class, 'invoicing_policy_version_id');
    }

    /**
     * Apply a status change the lifecycle allows, or refuse it with 409.
     */
    public function moveTo(InvoiceStatus $next, ?string $reason = null): void
    {
        if (! $this->status->canBecome($next)) {
            throw new ConflictHttpException("This invoice is {$this->status->value} and cannot become {$next->value}.");
        }

        $this->status = $next;
        $this->status_reason = $reason;
        $this->status_changed_at = now();
        if ($next === InvoiceStatus::Paid) {
            $this->paid_at = now();
        }
        $this->save();
    }

    /**
     * The next gap-free number for the prefix, zero-padded to the policy's width:
     * "PREFIX-000001". The sequence row is locked until the issuing transaction ends, so
     * concurrent issues queue.
     */
    private static function nextNumber(string $prefix, int $padding): string
    {
        DB::table('invoice_number_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $last = (int) DB::table('invoice_number_sequences')->where('prefix', $prefix)->lockForUpdate()->value('last_number');
        DB::table('invoice_number_sequences')->where('prefix', $prefix)->update(['last_number' => $last + 1, 'updated_at' => now()]);

        return sprintf('%s-%0'.$padding.'d', $prefix, $last + 1);
    }
}
