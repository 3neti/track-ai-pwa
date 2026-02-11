<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'uuid', 'unique:uploads,client_request_id'],
            'contract_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'mime' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
