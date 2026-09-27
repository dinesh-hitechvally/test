<?php

namespace App\Services\Analysis\Signals;

use App\Models\Stock;
use App\Services\Reports\TechnicalAnalysisReportService;
use Illuminate\Support\Collection;

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
     * Buy (or sell) signals with a price target/stop-loss attached, so "what
     * should I buy" comes with "at what price". Only run for the buy/sell
     * subset — the technical build() behind trade_setup is real per-stock
     * computation, not worth paying for every stock (today() covers those).
     *
     * @param  'buy'|'sell'  $bias
     */
    public function actionable(string $bias): Collection
    {
        $signals = $bias === 'sell' ? ['sell', 'strong_sell'] : ['buy', 'strong_buy'];

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
