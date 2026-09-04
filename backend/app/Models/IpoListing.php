<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'stage', 'symbol', 'company_name', 'units', 'price', 'sector', 'remark',
    'opening_date', 'closing_date', 'status', 'detail_url',
])]
class IpoListing extends Model
{
    protected function casts(): array
    {
        return [
            'units' => 'decimal:2',
            'price' => 'decimal:4',
            'opening_date' => 'date:Y-m-d',
            'closing_date' => 'date:Y-m-d',
        ];
    }
}
