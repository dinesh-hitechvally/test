<?php

namespace App\Http\Requests\Watchlist;

use Illuminate\Foundation\Http\FormRequest;

class SetWatchlistAlertRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'alert_price' => ['nullable', 'numeric', 'min:0'],
            'alert_direction' => ['nullable', 'in:above,below'],
        ];
    }
}
