<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An announcement IMC publishes on the public landing page (ADR-022). It names no
 * provider and makes nothing eligible; it is unrelated to service promotions (ADR-020).
 *
 * @property int $id
 * @property string $title
 * @property string $description
 * @property string|null $badge_text
 * @property string $color
 * @property string|null $link_path
 * @property list<string>|null $tags
 * @property string|null $countdown_text
 * @property string|null $cover_disk
 * @property string|null $cover_path
 * @property string|null $cover_mime_type
 * @property string|null $cover_alt
 * @property int $sort_order
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $published_at
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PublicAnnouncement extends Model
{
    /**
     * The colour presets of the landing-page advertisement cards (web client
     * colorOptions); the presentation only, never a meaning.
     */
    public const COLORS = ['green', 'blue', 'orange', 'red', 'beige', 'purple'];

    /**
     * The content fields an administrator edits. Publication, the cover file and the
     * authors are set only by their own actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'badge_text',
        'color',
        'link_path',
        'tags',
        'countdown_text',
        'cover_alt',
        'sort_order',
        'starts_at',
        'ends_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Published, started and not ended: what visitors see.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $now = now();
        $query->whereNotNull('published_at')
            ->where(fn (Builder $start) => $start->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * The order of the landing page: sort_order ascending, then the newest first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function displayOrder(Builder $query): void
    {
        $query->orderBy('sort_order')->orderByDesc('id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * `draft`, `scheduled` (published, not started), `live` or `ended`.
     */
    public function state(): string
    {
        $now = now();

        return match (true) {
            $this->published_at === null => 'draft',
            $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($now) => 'ended',
            $this->starts_at !== null && $this->starts_at->greaterThan($now) => 'scheduled',
            default => 'live',
        };
    }

    public function hasCover(): bool
    {
        return $this->cover_path !== null && $this->cover_disk !== null;
    }

    /**
     * The cover image inline, with the content type detected at upload. The caller
     * decides who may read it (SecurityHeaders keeps API responses out of caches).
     */
    public function coverResponse(): StreamedResponse
    {
        return Storage::disk((string) $this->cover_disk)->response((string) $this->cover_path, null, [
            'Content-Type' => (string) $this->cover_mime_type,
        ], 'inline');
    }

    public function deleteCoverFile(): void
    {
        if ($this->hasCover()) {
            Storage::disk((string) $this->cover_disk)->delete((string) $this->cover_path);
        }
    }
}
