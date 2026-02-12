<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class FaceLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'selfie' => ['required', 'image', 'max:5120'], // 5MB
            'transaction_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Please enter your username.',
            'selfie.required' => 'Please capture a photo.',
            'selfie.image' => 'The captured photo must be a valid image.',
            'selfie.max' => 'The photo is too large. Please try again.',
        ];
    }

    /**
     * Get the transaction ID, generating one if not provided.
     */
    public function transactionId(): string
    {
        return $this->input('transaction_id') ?? (string) \Illuminate\Support\Str::ulid();
    }
}
