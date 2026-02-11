<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class ProgressSubmitRequest extends FormRequest
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
            'checklist_items' => ['required', 'array', 'min:1'],
            'checklist_items.*.type' => ['required', 'string', 'in:top_view,left_side,right_side,front_view,back_view,detail'],
            'checklist_items.*.completed' => ['required', 'boolean'],
            'checklist_items.*.notes' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contract_id.required' => 'Please select a project.',
            'checklist_items.required' => 'At least one checklist item is required.',
            'checklist_items.min' => 'At least one checklist item is required.',
        ];
    }
}
