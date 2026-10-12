<?php

namespace App\Http\Requests\Api\V1;

use App\Models\SiteSetting;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The bulk update of landing-page texts, grouped by section:
 * `{ settings: { "<section>": { "<name>": { ar, en } } } }` (sections and names
 * are the two parts of the setting key; dots would not survive validation).
 * Only SPEC text keys are accepted; images change through the logo actions.
 */
class UpdateSiteSettingsRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', new SiteSetting);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'settings' => ['required', 'array'],
        ];

        foreach (SiteSetting::SPEC as $key => $meta) {
            if ($meta['type'] === SiteSetting::TYPE_IMAGE) {
                continue;
            }
            [$section, $name] = explode('.', $key, 2);
            $base = "settings.{$section}.{$name}";
            $rules[$base] = ['sometimes', 'array:ar,en'];
            $rules["{$base}.ar"] = ['sometimes', 'nullable', 'string', "max:{$meta['max']}"];
            $rules["{$base}.en"] = ['sometimes', 'nullable', 'string', "max:{$meta['max']}"];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sent = [];
            foreach ((array) $this->input('settings', []) as $section => $names) {
                foreach (array_keys((array) $names) as $name) {
                    $sent[] = "{$section}.{$name}";
                }
            }
            $unknown = array_values(array_diff($sent, SiteSetting::textKeys()));
            if ($unknown !== []) {
                $validator->errors()->add('settings', 'Unknown setting keys: '.implode(', ', $unknown).'.');
            }
        });
    }
}
