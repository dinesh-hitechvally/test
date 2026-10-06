<?php

namespace App\Services\Stocks;

use App\Models\Sector;
use App\Models\Stock;
use App\Services\Reports\PriceStatisticsService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Reading and creating stocks for the stock list / Stock Detail pages.
 * Series (prices, indicators, forecasts) come back oldest → newest, the
 * order the charts plot them in.
 */
class StockService
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

    /** Throws ModelNotFoundException (→ 404) for an unknown symbol. */
    public function findBySymbol(string $symbol, array $with = []): Stock
    {
        return Stock::with($with)->where('symbol', strtoupper($symbol))->firstOrFail();
    }

    /**
     * Every stock (or those whose symbol/name matches $search), with today's change_pct.
     *
     * $with / $changePct let a caller skip what it will not show: each relation is a query over
     * every stock, and change_pct is another. The sector is always loaded, because Stock::toArray()
     * reads it for every row.
     *
     * @param  list<string>  $with  any of latestPrice, latestSignal, aiOpinion
     */
    public function list(?string $search = null, array $with = ['latestPrice', 'latestSignal', 'aiOpinion'], bool $changePct = true): Collection
    {
        $query = Stock::query()->with(['sector', ...$with])->orderBy('symbol');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        $stocks = $query->get();

        return $changePct ? $this->prices->withChangePct($stocks) : $stocks;
    }

    /** @param  array{symbol: string, company_name?: ?string, sector?: ?string}  $data */
    public function create(array $data): Stock
    {
        $data['symbol'] = strtoupper($data['symbol']);

        $sectorName = $data['sector'] ?? null;
        unset($data['sector']);
        if ($sectorName !== null) {
            $data['sector_id'] = Sector::firstOrCreate(['name' => $sectorName])->id;
        }

        return Stock::create($data)->load('sector');
    }

    public function recentPrices(Stock $stock, int $days): Collection
    {
        return $this->latest($stock->dailyPrices(), $days);
    }

    public function recentIndicators(Stock $stock, int $days): Collection
    {
        return $this->latest($stock->technicalIndicators(), $days);
    }

    /**
     * Keyed by their own trade_date (the day each was generated from), not
     * the day being predicted — daily_prices only ever contains actual
     * trading days, so the frontend plots each one against the *next* entry
     * in the price series rather than needing a stored target date.
     */
    public function recentForecasts(Stock $stock, int $days): Collection
    {
        return $this->latest($stock->forecasts(), $days, ['trade_date', 'next_close']);
    }

    /**
     * One page of a stock's signal history. Page 1 = the most recent
     * $perPage days (within the date range, if set), page 2 = the days
     * before that — paged back from the newest, since that's how people
     * browse a signal history. Each row gets that day's forecast merged in
     * (what the estimator projected for the next close) so the table can
     * show prediction vs. outcome without a second request.
     *
     * @param  array{from?: ?string, to?: ?string, signal?: ?string[]}  $filters
     */
    public function signalHistory(Stock $stock, array $filters, int $page, int $perPage): array
    {
        $perPage = min($perPage, 200);
        $page = max($page, 1);

        $query = $stock->signals();
        if ($filters['from'] ?? null) {
            $query->where('trade_date', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->where('trade_date', '<=', $filters['to']);
        }
        if ($filters['signal'] ?? null) {
            $query->whereIn('signal', $filters['signal']);
        }

        $total = (clone $query)->count();

        $signals = $query
            ->orderByDesc('trade_date')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->reverse()
            ->values();

        $forecasts = $stock->forecasts()
            ->whereIn('trade_date', $signals->pluck('trade_date')->map(fn ($d) => $d->toDateString()))
            ->get()
            ->keyBy(fn ($f) => $f->trade_date->toDateString());

        $signals->each(function ($signal) use ($forecasts) {
            $forecast = $forecasts->get($signal->trade_date->toDateString());
            $signal->forecast_price = $forecast ? (float) $forecast->next_close : null;
        });

        return [
            'data' => $signals,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function dividends(Stock $stock): Collection
    {
        return $stock->dividends()->orderByDesc('fiscal_year')->get();
    }

    public function rightShares(Stock $stock): Collection
    {
        return $stock->rightShares()->orderByDesc('opening_date')->get();
    }

    /** The newest $days rows of a per-day relation, returned oldest → newest. */
    private function latest(HasMany $relation, int $days, array $columns = ['*']): Collection
    {
        return $relation->orderByDesc('trade_date')->limit($days)->get($columns)->reverse()->values();
    }
}
