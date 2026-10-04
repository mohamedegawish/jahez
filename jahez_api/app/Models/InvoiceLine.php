<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice: a whole-number quantity times a unit amount, computed exactly
 * in minor units. Lines change only while the invoice is a draft.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property string $description
 * @property int $quantity
 * @property string $unit_amount
 * @property string $line_amount
 */
class InvoiceLine extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'line_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
