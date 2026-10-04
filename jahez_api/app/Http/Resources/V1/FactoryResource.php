<?php

namespace App\Http\Resources\V1;

use App\Models\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `current_readiness` is the factory's latest digital readiness assessment (ADR-018), or
 * null before the first one. Legacy manual classifications are not a current
 * classification; they are listed at /factories/{id}/assessments.
 *
 * @mixin Factory
 */
class FactoryResource extends JsonResource
{
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
            'legal_name' => $this->legal_name,
            'size' => $this->size,
            'contact_name' => $this->contact_name,
            'contact_job_title' => $this->contact_job_title,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website' => $this->website,
            'governorate' => $this->governorate,
            'city' => $this->city,
            'address' => $this->address,
            'commercial_registration_number' => $this->commercial_registration_number,
            'tax_registration_number' => $this->tax_registration_number,
            'sectors' => SectorResource::collection($this->whenLoaded('sectors')),
            'documents' => $this->whenLoaded('activeDocuments', fn (): array => OrganizationDocumentResource::byType($this->activeDocuments)),
            // The onboarding steps (ADR-019): the profile fields still to fill (OQ-19)
            // and whether the readiness assessment has been completed. Informational: an
            // assessment is never blocked by a missing field.
            'onboarding' => $this->when($this->relationLoaded('currentReadinessAssessment'), fn (): array => $this->onboarding()),
            'current_readiness' => $this->whenLoaded('currentReadinessAssessment', fn (): ?array => $this->currentReadiness()),
            // IMC review of the account (ADR-021); separate from the readiness category.
            'approval' => $this->approval(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{missing_profile_fields: list<string>, profile_complete: bool, readiness_status: string}
     */
    protected function onboarding(): array
    {
        $missing = $this->missingProfileFields();

        return [
            'missing_profile_fields' => $missing,
            'profile_complete' => $missing === [],
            'readiness_status' => $this->currentReadinessAssessment === null ? 'not_started' : 'completed',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function currentReadiness(): ?array
    {
        $assessment = $this->currentReadinessAssessment;

        return $assessment === null ? null : [
            'assessment_id' => $assessment->id,
            'questionnaire_version' => $assessment->questionnaire?->version,
            'total_score' => $assessment->total_score,
            'category' => $assessment->category === null ? null : [
                'code' => $assessment->category->code->value,
                'name_en' => $assessment->category->name_en,
                'name_ar' => $assessment->category->name_ar,
            ],
            'completed_at' => $assessment->completed_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{status: string, reason: string|null, changed_at: string|null, may_send_requests: bool}
     */
    protected function approval(): array
    {
        return [
            'status' => $this->approval_status->value,
            'reason' => $this->approval_reason,
            'changed_at' => $this->approval_changed_at?->toIso8601ZuluString(),
            'may_send_requests' => $this->maySendServiceRequests(),
        ];
    }
}
