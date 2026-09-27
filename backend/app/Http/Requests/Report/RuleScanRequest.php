<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class RuleScanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['string'],
            'mode' => ['nullable', 'in:any,all'],
        ];
    }
}
