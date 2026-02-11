<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UploadInitRequest extends FormRequest
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
            'document_type' => ['required', 'string', 'in:purchase_order,equipment_pictures,delivery_receipts,meals,documents,other'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
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
            'document_type.required' => 'Please select a document type.',
            'document_type.in' => 'Invalid document type selected.',
        ];
    }
}
