<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class ProjectProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'contract_id' => ['nullable', 'string', 'max:255'],
            'current_milestone' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'previous_progress_file_ids' => ['nullable', 'array'],
            'previous_progress_file_ids.*' => ['string', 'max:255'],
            'current_progress_file_ids' => ['nullable', 'array'],
            'current_progress_file_ids.*' => ['string', 'max:255'],
            'geo_location' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remarks.max' => 'Engineer remarks cannot exceed 2000 characters.',
        ];
    }
}
