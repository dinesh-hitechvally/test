<?php

namespace App\Http\Requests\Portfolio;

use Illuminate\Foundation\Http\FormRequest;

class SetPortfolioCashRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cash_balance' => ['required', 'numeric', 'min:0'],
        ];
    }
}
