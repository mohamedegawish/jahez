<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PublicAnnouncement;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The content of a landing-page announcement (ADR-022), for creation (every required
 * field) and for a partial update (only the fields sent). The link is a path of the web
 * client only: the public page never sends visitors to an address an administrator
 * typed. Publication and the cover file have their own actions.
 */
class SavePublicAnnouncementRequest extends FormRequest
{
    /**
     * A path of the web client: one leading slash, no scheme or host, an optional
     * simple query string.
     */
    public const LINK_PATTERN = '/^\/(?!\/)[A-Za-z0-9\/_-]*(\?[A-Za-z0-9=&_-]*)?$/';

    public function authorize(): Response
    {
        $announcement = $this->route('publicAnnouncement');

        return $announcement instanceof PublicAnnouncement
            ? Gate::inspect('update', $announcement)
            : Gate::inspect('create', PublicAnnouncement::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:120'],
            'description' => [$required, 'string', 'max:600'],
            'color' => [$required, 'string', Rule::in(PublicAnnouncement::COLORS)],
            'badge_text' => ['sometimes', 'nullable', 'string', 'max:40'],
            'link_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.self::LINK_PATTERN],
            'tags' => ['sometimes', 'nullable', 'list', 'max:5'],
            'tags.*' => ['string', 'max:30', 'distinct'],
            'countdown_text' => ['sometimes', 'nullable', 'string', 'max:60'],
            'cover_alt' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
        ];
    }

    /**
     * Error messages for the rules whose default text would not help.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'link_path.regex' => 'The link must be a path of this platform that starts with a slash, for example /register/factory.',
        ];
    }
}
