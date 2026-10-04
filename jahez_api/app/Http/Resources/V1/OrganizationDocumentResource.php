<?php

namespace App\Http\Resources\V1;

use App\Enums\DocumentType;
use App\Models\OrganizationDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An uploaded document's metadata. Never the storage path or a public URL: the file is
 * read through the organization's authorized document endpoint (ADR-019).
 *
 * @mixin OrganizationDocument
 */
class OrganizationDocumentResource extends JsonResource
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
            'type' => $this->type->value,
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * One entry per document type, null where nothing was uploaded.
     *
     * @param  iterable<OrganizationDocument>  $documents
     * @return array<string, self|null>
     */
    public static function byType(iterable $documents): array
    {
        $byType = array_fill_keys(array_map(fn (DocumentType $type): string => $type->value, DocumentType::cases()), null);
        foreach ($documents as $document) {
            $byType[$document->type->value] = new self($document);
        }

        return $byType;
    }
}
