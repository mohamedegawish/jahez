<?php

namespace App\Http\Resources\V1;

use App\Enums\DocumentType;
use App\Models\Factory;
use App\Models\OrganizationDocument;
use Illuminate\Http\Request;

/**
 * A factory in the IMC list (`GET /factories`, ADR-021 and ADR-018 addendum 2): what a
 * card shows. It leaves out the legal and contact details (legal name, contact person,
 * email, phone, address, commercial and tax registration numbers) and every document
 * but the logo; those are on `GET /factories/{id}` and the review screen only.
 *
 * Expects sectors, the active logo document (as `activeDocuments`), the current
 * readiness assessment with its questionnaire and category, and the service-request
 * count to be loaded.
 *
 * @mixin Factory
 */
class FactorySummaryResource extends FactoryResource
{
    /**
     * The profile fields whose completion the review queue shows; only whether each is
     * filled is listed, never its value.
     */
    private const COMPLETION_FIELDS = [
        'legal_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'governorate',
        'address',
        'commercial_registration_number',
        'tax_registration_number',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'governorate' => $this->governorate,
            'city' => $this->city,
            'sectors' => SectorResource::collection($this->whenLoaded('sectors')),
            // Fetched through GET /factories/{id}/documents/{document}, like any document.
            'logo' => $this->whenLoaded('activeDocuments', fn (): ?array => $this->logo()),
            'onboarding' => $this->when($this->relationLoaded('currentReadinessAssessment'), fn (): array => $this->onboarding()),
            'current_readiness' => $this->whenLoaded('currentReadinessAssessment', fn (): ?array => $this->currentReadiness()),
            'approval' => $this->approval(),
            'profile_completion' => $this->when($this->relationLoaded('sectors') && $this->relationLoaded('activeDocuments'), fn (): array => $this->profileCompletion()),
            'service_requests_count' => $this->whenCounted('serviceRequests'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Which of the profile fields, the sectors and the logo are filled: names only.
     *
     * @return array{filled: int, total: int, missing: list<string>}
     */
    private function profileCompletion(): array
    {
        $missing = array_values(array_filter(self::COMPLETION_FIELDS, fn (string $field): bool => $this->getAttribute($field) === null || $this->getAttribute($field) === ''));
        if ($this->sectors->isEmpty()) {
            $missing[] = 'sectors';
        }
        if ($this->logo() === null) {
            $missing[] = 'logo';
        }
        $total = count(self::COMPLETION_FIELDS) + 2;

        return ['filled' => $total - count($missing), 'total' => $total, 'missing' => $missing];
    }

    /**
     * @return array{id: int, uploaded_at: string|null}|null
     */
    private function logo(): ?array
    {
        $logo = $this->activeDocuments->first(fn (OrganizationDocument $document): bool => $document->type === DocumentType::Logo);

        return $logo === null ? null : [
            'id' => $logo->id,
            'uploaded_at' => $logo->created_at?->toIso8601ZuluString(),
        ];
    }
}
