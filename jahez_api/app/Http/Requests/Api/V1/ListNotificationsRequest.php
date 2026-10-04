<?php

namespace App\Http\Requests\Api\V1;

/**
 * Listing the signed-in account's notifications; `filter[unread]` keeps unread ones.
 */
class ListNotificationsRequest extends ListRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter' => ['sometimes', 'array:unread'],
            'filter.unread' => ['sometimes', 'boolean'],
        ];
    }
}
