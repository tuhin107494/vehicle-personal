<?php

namespace App\Http\Requests;

class PhotoRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Choose a photo.',
            'photo.image' => 'The file must be an image.',
            'photo.mimes' => 'Use a JPG, PNG or WebP image.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
            'photo.uploaded' => 'The photo could not be uploaded. Use an image of 2 MB or less.',
        ];
    }
}
