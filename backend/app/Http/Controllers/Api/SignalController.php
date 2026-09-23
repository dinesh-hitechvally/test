<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\MarketData\TechnicalAnalysisReportService;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    /**
     * Dashboard feed: every stock's most recent signal, newest-scored first.
     */
    public function today(Request $request)
    {
        $stocks = Stock::query()
            ->with(['sector', 'latestSignal', 'latestPrice'])
            ->whereHas('latestSignal')
            ->get();

        if ($filter = $request->query('signal')) {
            $stocks = $stocks->filter(fn ($s) => $s->latestSignal?->signal === $filter);
        }

        $sorted = $stocks->sortByDesc(fn ($s) => (float) $s->latestSignal?->score)->values();

        return response()->json($sorted);
    }

    /**
     * Buy/Sell Signals pages: same rule-based signal as today(), but with a
     * price target/stop-loss attached so "what should I buy" comes with "at
     * what price" — not run for every stock (today() does that, cheaply,
     * dashboard-wide) since the technical build() behind trade_setup is real
     * per-stock computation, only worth paying for on the smaller buy/sell
     * subset this actually serves.
     */
    public function actionable(Request $request, TechnicalAnalysisReportService $technical)
    {
        $bias = $request->query('bias') === 'sell' ? 'sell' : 'buy';
        $signals = $bias === 'sell' ? ['sell', 'strong_sell'] : ['buy', 'strong_buy'];

        $stocks = Stock::query()
            ->with(['sector', 'latestSignal', 'latestPrice'])
            ->whereHas('latestSignal', fn ($q) => $q->whereIn('signal', $signals))
            ->get();

        $rows = $stocks->map(function ($stock) use ($technical) {
            $report = $technical->build($stock);

            return [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector?->name,
                'close' => $stock->latestPrice?->close_price,
                'signal' => $stock->latestSignal->signal,
                'score' => (float) $stock->latestSignal->score,
                'reasons' => $stock->latestSignal->reasons,
                // Trade setup reflects the stock's own technical trend, which
                // can honestly disagree with the rule-based signal above (a
                // "Buy" signal can fire on a stock whose broader trend still
                // reads bearish/sideways) — surfaced as-is, not forced to agree.
                'trade_setup' => $report['available'] ? $report['trade_setup'] : null,
            ];
        });

        // Highest conviction first — for buy that's the most positive score,
        // for sell the most negative (furthest from zero in the bearish direction).
        $rows = $bias === 'sell' ? $rows->sortBy('score')->values() : $rows->sortByDesc('score')->values();

        return response()->json($rows);
    }
}
