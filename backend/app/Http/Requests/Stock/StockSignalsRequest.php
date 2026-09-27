<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class StockSignalsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'signal' => ['nullable', 'array'],
            'signal.*' => ['string', 'in:strong_buy,buy,hold,sell,strong_sell'],
        ];
    }
}
