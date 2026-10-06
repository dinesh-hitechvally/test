<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'cash_balance'])]
class Portfolio extends Model
{
    protected function casts(): array
    {
        return ['cash_balance' => 'decimal:4'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PortfolioTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PortfolioTransaction::class);
    }

    /** @return HasMany<BuyOrder, $this> */
    public function buyOrders(): HasMany
    {
        return $this->hasMany(BuyOrder::class);
    }

    /**
     * Newest first, with the stock's symbol/name — the order every listing
     * and export shows transactions in.
     *
     * @return HasMany<PortfolioTransaction, $this>
     */
    public function transactionHistory(): HasMany
    {
        return $this->transactions()
            ->with('stock:id,symbol,company_name')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');
    }
}
