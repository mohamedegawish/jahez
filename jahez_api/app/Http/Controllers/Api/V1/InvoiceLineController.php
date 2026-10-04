<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInvoiceLineRequest;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lines of a draft invoice. Once issued, the lines are frozen.
 */
class InvoiceLineController extends Controller
{
    public function store(StoreInvoiceLineRequest $request, Invoice $invoice): InvoiceResource
    {
        DB::transaction(function () use ($request, $invoice): void {
            $locked = $this->lockDraft($invoice);
            $locked->addLine($request->string('description')->toString(), $request->integer('quantity'), (string) $request->input('unit_amount'));
        });

        return new InvoiceResource($invoice->refresh()->load('lines'));
    }

    public function destroy(Invoice $invoice, InvoiceLine $line): InvoiceResource
    {
        Gate::authorize('manage', $invoice);

        DB::transaction(function () use ($invoice, $line): void {
            $this->lockDraft($invoice)->removeLine($line);
        });

        return new InvoiceResource($invoice->refresh()->load('lines'));
    }

    private function lockDraft(Invoice $invoice): Invoice
    {
        $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

        if ($locked->status !== InvoiceStatus::Draft) {
            throw new ConflictHttpException("This invoice is {$locked->status->value}; only a draft's lines can change.");
        }

        return $locked;
    }
}
