<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
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
            'upload_id' => ['nullable', 'integer', 'exists:uploads,id'],
            'entry_id' => ['required_without:upload_id', 'nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480'], // 20MB max
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entry_id.required_without' => 'Either upload_id or entry_id is required.',
            'file.required' => 'Please select a file to upload.',
            'file.max' => 'File size cannot exceed 20MB.',
        ];
    }
}
