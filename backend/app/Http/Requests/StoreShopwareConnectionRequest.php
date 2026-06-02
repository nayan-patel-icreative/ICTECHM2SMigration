<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopwareConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_url'                 => ['required', 'url', 'max:255'],
            'client_id'               => ['required', 'string', 'max:255'],
            'client_secret'           => ['nullable', 'string', 'max:255'],
            'language_config'         => ['nullable', 'array'],
            'language_config.*.id'      => ['required_with:language_config', 'string'],
            'language_config.*.name'    => ['nullable', 'string'],
            'language_config.*.locale'  => ['nullable', 'string'],
            'language_config.*.enabled' => ['nullable', 'boolean'],
            // Sales Channel scoping
            'sales_channel_id'        => ['nullable', 'string', 'max:64'],
            'sales_channel_name'      => ['nullable', 'string', 'max:255'],
            'navigation_category_id'  => ['nullable', 'string', 'max:64'],
        ];
    }
}
