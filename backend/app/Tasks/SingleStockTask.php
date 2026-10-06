<?php

namespace App\Tasks;

use App\Models\Stock;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Task that works on ONE stock named in the URL (/cron/fetch/<what>/NABIL): the one-stock
 * counterpart to a PerStockTask. Re-running it re-fetches (the fetches are upserts), so it is
 * also how a stock that is already done gets refreshed, or one flagged with an error gets
 * retried by hand.
 *
 * A subclass only says what to do with the stock.
 */
abstract class SingleStockTask extends Task
{
    protected string $symbol = '';

    public function withRequest(Request $request): static
    {
        $this->symbol = strtoupper((string) $request->route('symbol'));

        return $this;
    }

    public function name(): string
    {
        return parent::name()." {$this->symbol}";
    }

    /** @throws RuntimeException when no stock has the symbol from the URL */
    protected function stock(): Stock
    {
        return Stock::where('symbol', $this->symbol)->first()
            ?? throw new RuntimeException("No stock found for symbol [{$this->symbol}].");
    }
}
