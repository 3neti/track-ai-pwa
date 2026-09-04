<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class FaceRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selfie' => ['required', 'image', 'max:5120'],
            'document' => ['required', 'image', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'selfie.required' => 'Please capture your selfie.',
            'selfie.image' => 'The selfie must be a valid image.',
            'document.required' => 'Please capture the document image.',
            'document.image' => 'The document image must be a valid image.',
        ];
    }
}
