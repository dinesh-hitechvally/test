<?php

namespace App\Http\Requests\Portfolio;

use Illuminate\Foundation\Http\FormRequest;

class SetPositionTargetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'stop_loss' => ['nullable', 'numeric', 'min:0'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
