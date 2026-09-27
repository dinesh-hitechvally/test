<?php

namespace App\Http\Requests\SavedScreen;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavedScreenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['required', 'array'],
        ];
    }
}
