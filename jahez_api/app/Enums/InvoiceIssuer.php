<?php

namespace App\Enums;

/**
 * Who issues the invoice for an agreement (OQ-16; config jahez.billing.invoice_issuer).
 * The sources name neither, so the owner chooses.
 */
enum InvoiceIssuer: string
{
    /** IMC administrators holding invoices.manage. */
    case Imc = 'imc';

    /** The members of the provider that agreed. */
    case ServiceProvider = 'service_provider';
}
