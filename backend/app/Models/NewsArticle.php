<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'url', 'published_date'])]
class NewsArticle extends Model
{
    protected function casts(): array
    {
        return [
            'published_date' => 'date:Y-m-d',
        ];
    }
}
