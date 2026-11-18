<?php

namespace App\Http\Requests\Gateway;

use App\Http\Requests\ApiRequest;

/** A bank transfer's reference and, when the gateway asks for it, a photo of the receipt. Whether the proof is required is checked in the controller against the gateway's setting. */
class ManualReferenceRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.required' => 'Enter the transfer reference or transaction number.',
            'proof.image' => 'The proof must be an image.',
            'proof.mimes' => 'Use a JPG, PNG or WebP image.',
            'proof.max' => 'The proof must be 4 MB or smaller.',
        ];
    }
}
