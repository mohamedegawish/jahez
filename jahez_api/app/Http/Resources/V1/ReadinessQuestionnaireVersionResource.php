<?php

namespace App\Http\Resources\V1;

use App\Models\ReadinessQuestionnaire;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A questionnaire version for IMC administrators (ADR-018 addendum): its status
 * (draft, current or retired), when it was published, how many assessments answered it
 * and, when the structure is loaded, the full definition as factories see it plus the
 * category roadmap texts.
 *
 * @mixin ReadinessQuestionnaire
 */
class ReadinessQuestionnaireVersionResource extends JsonResource
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
            'version' => $this->version,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'source_ref' => $this->source_ref,
            'status' => match (true) {
                $this->is_current === true => 'current',
                $this->isDraft() => 'draft',
                default => 'retired',
            },
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'assessments_count' => $this->whenCounted('assessments'),
            'definition' => $this->when(
                $this->relationLoaded('pillars') && $this->relationLoaded('categories'),
                fn (): array => [
                    ...(new ReadinessQuestionnaireResource($this->resource))->toArray($request),
                    'categories' => $this->categories->map(fn ($category): array => [
                        ...(new ReadinessCategoryResource($category))->resolve($request),
                        'focus_ar' => $category->focus_ar,
                        'steps_ar' => $category->steps_ar,
                    ])->all(),
                ],
            ),
            // Who drafted, last changed and published the version; null for the seeded
            // source version (ADR-018 addendum 2).
            'created_by' => $this->whenLoaded('createdBy', fn (): ?array => self::actor($this->createdBy)),
            'updated_by' => $this->whenLoaded('updatedBy', fn (): ?array => self::actor($this->updatedBy)),
            'published_by' => $this->whenLoaded('publishedBy', fn (): ?array => self::actor($this->publishedBy)),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private static function actor(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
