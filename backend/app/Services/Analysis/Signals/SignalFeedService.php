<?php

namespace App\Services\Analysis\Signals;

use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Models\User;
use App\Services\Reports\TechnicalAnalysisReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The signal feeds: the dashboard's "today" list and the Buy/Sell Signals pages. */
class SignalFeedService
{
    public function __construct(
        private readonly TechnicalAnalysisReportService $technical,
        private readonly SignalConditionScorer $scorer,
        private readonly SignalSettingsService $settings,
    ) {}

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
     * hold reason is done by the page. With a user whose own settings differ from the system's, each day is re-decided
     * from the stored category percentages under those settings (weights, minimum, margin, guard).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function board(?User $user = null): Collection
    {
        // No signals yet (nothing generated): an empty page, and nothing else is touched.
        if (! DB::table('signals')->exists()) {
            return collect();
        }

        // The breakdown row of each stock's latest signal day, in one query.
        $latestDay = DB::table('signals')->select('stock_id', DB::raw('max(trade_date) as trade_date'))->groupBy('stock_id');
        $mine = $this->settings->forUser($user);
        $custom = $this->settings->isCustom($mine);

        $columns = ['b.stock_id', 'b.buy_pct', 'b.sell_pct', 'b.hold_pct', 'b.hold_type'];
        if ($custom) {
            foreach (SignalBreakdown::CATEGORIES as $category) {
                array_push($columns, "b.{$category}_buy", "b.{$category}_sell", "b.{$category}_hold");
            }
            array_push($columns, 'i.rsi_14', 'i.bb_upper', 'i.bb_lower');
        }

        $query = DB::table('signal_breakdowns as b')
            ->joinSub($latestDay, 'l', fn ($join) => $join->on('l.stock_id', '=', 'b.stock_id')->on('l.trade_date', '=', 'b.trade_date'));
        if ($custom) {
            $query->leftJoin('technical_indicators as i', fn ($join) => $join->on('i.stock_id', '=', 'b.stock_id')->on('i.trade_date', '=', 'b.trade_date'));
        }
        $breakdowns = $query->get($columns)->keyBy('stock_id');

        return Stock::query()
            ->with(['sector', 'latestSignal', 'latestPrice'])
            ->whereHas('latestSignal')
            ->get()
            ->map(function ($stock) use ($breakdowns, $custom, $mine) {
                $b = $breakdowns->get($stock->id);
                $signal = $stock->latestSignal->signal;
                $score = (float) $stock->latestSignal->score;
                $holdType = $b?->hold_type;
                $reasons = $stock->latestSignal->reasons;

                if ($custom && $b) {
                    [$signal, $score, $holdType, $reasons, $b] = $this->redecide($stock, $b, $mine);
                }

                return [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'sector' => $stock->sector?->name,
                    'close' => $stock->latestPrice?->close_price,
                    'trade_date' => $stock->latestSignal->trade_date->toDateString(),
                    'signal' => $signal,
                    'score' => $score,
                    'buy_pct' => $b ? (float) $b->buy_pct : null,
                    'sell_pct' => $b ? (float) $b->sell_pct : null,
                    'hold_pct' => $b ? (float) $b->hold_pct : null,
                    'hold_type' => $holdType,
                    'reasons' => $reasons,
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * One stock's latest day decided again under the person's settings.
     *
     * @return array{0: string, 1: float, 2: ?string, 3: list<string>, 4: object}
     */
    private function redecide(Stock $stock, object $b, array $settings): array
    {
        $categories = [];
        foreach (SignalBreakdown::CATEGORIES as $category) {
            $categories[$category] = $b->{"{$category}_buy"} === null ? null : [
                'buy' => (float) $b->{"{$category}_buy"},
                'sell' => (float) $b->{"{$category}_sell"},
                'hold' => (float) $b->{"{$category}_hold"},
            ];
        }

        $close = $stock->latestPrice?->close_price !== null ? (float) $stock->latestPrice->close_price : null;
        $pb = $close !== null && $b->bb_upper !== null && $b->bb_lower !== null && (float) $b->bb_upper > (float) $b->bb_lower
            ? ($close - (float) $b->bb_lower) / ((float) $b->bb_upper - (float) $b->bb_lower)
            : null;

        $result = $this->scorer->reapply($categories, $b->rsi_14 !== null ? (float) $b->rsi_14 : null, $pb, $settings);
        $final = $result['final'];

        $holdType = $b->hold_type;
        if ($result['decision'] !== 'hold') {
            $holdType = null;
        } elseif ($result['guard'] !== null) {
            $holdType = $result['guard']['kind'] === 'oversold' ? 'wait_confirmation' : 'overbought';
        } elseif ($holdType === null) {
            $holdType = 'consolidation';
        }

        $reasons = [
            sprintf('Your settings — Decision %s: Buy %.1f%% · Sell %.1f%% · Hold %.1f%%.', strtoupper($result['decision']), $final['buy'], $final['sell'], $final['hold']),
            ...($result['guard'] !== null ? [$result['guard']['reason']] : []),
        ];

        $b->buy_pct = $final['buy'];
        $b->sell_pct = $final['sell'];
        $b->hold_pct = $final['hold'];

        return [$result['decision'], round(($final['buy'] - $final['sell']) / 100, 4), $holdType, $reasons, $b];
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
