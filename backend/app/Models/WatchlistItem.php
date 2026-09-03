<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['watchlist_id', 'stock_id'])]
class WatchlistItem extends Model
{
    /** @return BelongsTo<Watchlist, $this> */
    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class);
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
