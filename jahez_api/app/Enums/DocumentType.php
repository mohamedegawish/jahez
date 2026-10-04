<?php

namespace App\Enums;

/**
 * The files an organization may upload (ADR-019). The owner named the logo and the tax
 * and commercial registration documents on 2026-10-03; none is mandatory until OQ-18
 * decides the required documents.
 */
enum DocumentType: string
{
    case Logo = 'logo';
    case CommercialRegistration = 'commercial_registration';
    case TaxRegistration = 'tax_registration';

    /**
     * The multipart request field that carries each document type.
     */
    public const REQUEST_FIELDS = [
        'logo' => self::Logo,
        'commercial_registration_document' => self::CommercialRegistration,
        'tax_registration_document' => self::TaxRegistration,
    ];

    /**
     * Legal documents IMC verifies: once a provider is approved, its members replace them
     * only through a reviewed change request. A logo is an ordinary profile field.
     */
    public function isLegal(): bool
    {
        return $this !== self::Logo;
    }

    /**
     * The file extensions accepted, checked against the detected content type.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return array_values((array) config($this->isLegal() ? 'jahez.documents.legal_mimes' : 'jahez.documents.logo_mimes'));
    }

    /**
     * The largest accepted file, in kilobytes.
     */
    public function maxKilobytes(): int
    {
        return (int) config($this->isLegal() ? 'jahez.documents.legal_max_kb' : 'jahez.documents.logo_max_kb');
    }

    /**
     * Validation rules for an uploaded file of this type. `mimes` checks the detected
     * content, not the client's file name or declared type.
     *
     * @return list<string>
     */
    public function fileRules(): array
    {
        return ['file', 'mimes:'.implode(',', $this->allowedExtensions()), 'max:'.$this->maxKilobytes()];
    }
}
