<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
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
            'contract_id' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'client_request_id' => ['nullable', 'string', 'uuid', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contract_id.required' => 'Please select a project.',
            'latitude.required' => 'Location access is required for check-in.',
            'longitude.required' => 'Location access is required for check-in.',
        ];
    }
}
