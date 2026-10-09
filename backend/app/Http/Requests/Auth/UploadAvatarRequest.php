<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 3 MB is plenty: it is cropped to a 256 px square on the server.
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }
}
