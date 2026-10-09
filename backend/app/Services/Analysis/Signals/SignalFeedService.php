<?php

namespace App\Services\Analysis\Signals;

use App\Models\Stock;
use App\Services\Reports\TechnicalAnalysisReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The signal feeds: the dashboard's "today" list and the Buy/Sell Signals pages. */
class SignalFeedService
{
    public function __construct(private readonly TechnicalAnalysisReportService $technical) {}

    /** Every stock's most recent signal (optionally only one signal type), highest score first. */
    public function today(?string $signal = null): Collection
    {
        $stocks = Stock::query()
            ->with(['sector', 'latestSignal', 'latestPrice'])
            ->whereHas('latestSignal')
            ->get();

        if ($signal) {
            $stocks = $stocks->filter(fn ($s) => $s->latestSignal?->signal === $signal);
        }

        return $stocks->sortByDesc(fn ($s) => (float) $s->latestSignal?->score)->values();
    }

    /**
     * The Signals page: every stock's latest signal with the percentages behind it (from signal_breakdowns), cheap
     * enough to load for the whole market — no per-stock technical build. Filtering by signal, sector, confidence or
     * hold reason is done by the page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function board(): Collection
    {
        // The breakdown row of each stock's latest signal day, in one query.
        $latestDay = DB::table('signals')->select('stock_id', DB::raw('max(trade_date) as trade_date'))->groupBy('stock_id');
        $breakdowns = DB::table('signal_breakdowns as b')
            ->joinSub($latestDay, 'l', fn ($join) => $join->on('l.stock_id', '=', 'b.stock_id')->on('l.trade_date', '=', 'b.trade_date'))
            ->get(['b.stock_id', 'b.buy_pct', 'b.sell_pct', 'b.hold_pct', 'b.hold_type'])
            ->keyBy('stock_id');

        return Stock::query()
            ->with(['sector', 'latestSignal', 'latestPrice'])
            ->whereHas('latestSignal')
            ->get()
            ->map(function ($stock) use ($breakdowns) {
                $b = $breakdowns->get($stock->id);

                return [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'sector' => $stock->sector?->name,
                    'close' => $stock->latestPrice?->close_price,
                    'trade_date' => $stock->latestSignal->trade_date->toDateString(),
                    'signal' => $stock->latestSignal->signal,
                    'score' => (float) $stock->latestSignal->score,
                    'buy_pct' => $b ? (float) $b->buy_pct : null,
                    'sell_pct' => $b ? (float) $b->sell_pct : null,
                    'hold_pct' => $b ? (float) $b->hold_pct : null,
                    'hold_type' => $b?->hold_type,
                    'reasons' => $stock->latestSignal->reasons,
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Buy (or sell) signals with a price target/stop-loss attached, so "what
     * should I buy" comes with "at what price". Only run for the buy/sell
     * subset — the technical build() behind trade_setup is real per-stock
     * computation, not worth paying for every stock (today() covers those).
     *
     * @param  'buy'|'sell'  $bias
     */
    public function actionable(string $bias): Collection
    {
        $signals = $bias === 'sell' ? ['sell'] : ['buy'];

        $rows = Stock::query()
            ->with(['sector', 'latestSignal', 'latestPrice'])
            ->whereHas('latestSignal', fn ($q) => $q->whereIn('signal', $signals))
            ->get()
            ->map(function ($stock) {
                $report = $this->technical->build($stock);

                return [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'sector' => $stock->sector?->name,
                    'close' => $stock->latestPrice?->close_price,
                    'signal' => $stock->latestSignal->signal,
                    'score' => (float) $stock->latestSignal->score,
                    'reasons' => $stock->latestSignal->reasons,
                    // The trade setup follows the stock's own technical trend,
                    // which can honestly disagree with the rule-based signal —
                    // surfaced as-is, not forced to agree.
                    'trade_setup' => $report['available'] ? $report['trade_setup'] : null,
                ];
            });

        // Highest conviction first: most positive score for buy, most negative for sell.
        return $bias === 'sell' ? $rows->sortBy('score')->values() : $rows->sortByDesc('score')->values();
    }
}
