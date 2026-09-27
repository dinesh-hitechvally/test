<?php

namespace App\Http\Requests\Watchlist;

use Illuminate\Foundation\Http\FormRequest;

class AddWatchlistItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'stock_id' => ['required', 'exists:stocks,id'],
        ];
    }
}
