<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', 'max:20', 'unique:stocks,symbol'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:100'],
        ];
    }
}
