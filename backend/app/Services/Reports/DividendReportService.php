<?php

namespace App\Services\Reports;

use App\Models\Stock;
use Illuminate\Support\Collection;

/**
 * The Dividend report and each stock's dividend summary (yield, consistency,
 * bonus history).
 */
class DividendReportService
{
    private const DIVIDEND_FACE_VALUE = 100.0;

    /**
     * Market-wide dividend ranking — one row per stock that has at least one
     * recorded dividend, ranked by trailing dividend yield. Cash dividend %
     * is declared against face value, not market price — always assumed to
     * be Rs. 100 here (DIVIDEND_FACE_VALUE), which is wrong for instruments
     * with a different face value (e.g. mutual fund units, commonly Rs. 10)
     * now that stocks.face_value has been removed; such stocks will show an
     * inflated yield until a per-stock face value is captured again.
     *
     * @return array{totals: array, stocks: Collection}
     */
    public function dividendReport(?string $sector = null): array
    {
        $query = Stock::query()->with([
            'sector', 'latestPrice', 'latestSignal',
            'dividends' => fn ($q) => $q->orderByDesc('fiscal_year'),
            'rightShares' => fn ($q) => $q->orderByDesc('opening_date'),
        ]);

        if ($sector) {
            $query->whereHas('sector', fn ($q) => $q->where('name', $sector));
        }

        $rows = $query->get()
            ->filter(fn ($stock) => $stock->dividends->isNotEmpty())
            ->map(fn ($stock) => $this->summarizeDividends($stock))
            ->sortByDesc(fn ($r) => $r['dividend_yield_pct'] ?? -1)
            ->values();

        $yields = $rows->pluck('dividend_yield_pct')->filter(fn ($v) => $v !== null);

        return [
            'totals' => [
                'stocks_with_dividends' => $rows->count(),
                'avg_yield_pct' => $yields->isNotEmpty() ? round($yields->avg(), 2) : null,
                'top_yield_pct' => $yields->isNotEmpty() ? $yields->max() : null,
                'stocks_with_right_shares' => $rows->filter(fn ($r) => $r['right_share_count'] > 0)->count(),
            ],
            'top_picks' => $this->rankDividendPicks($rows),
            'stocks' => $rows,
        ];
    }

    /**
     * Single-stock dividend summary — same shape/math as one row of
     * dividendReport(), reused there and by the Analyst Report so the yield
     * calculation can't drift between the two. Returns null if the stock has
     * no recorded dividend history.
     */
    public function stockDividendSummary(Stock $stock): ?array
    {
        $stock->loadMissing([
            'sector', 'latestPrice', 'latestSignal',
            'dividends' => fn ($q) => $q->orderByDesc('fiscal_year'),
            'rightShares' => fn ($q) => $q->orderByDesc('opening_date'),
        ]);

        if ($stock->dividends->isEmpty()) {
            return null;
        }

        return $this->summarizeDividends($stock);
    }

    private function summarizeDividends(Stock $stock): array
    {
        $latest = $stock->dividends->first();
        $close = $stock->latestPrice?->close_price;
        $latestCashRs = $latest->cash_dividend_pct !== null
            ? (float) $latest->cash_dividend_pct * self::DIVIDEND_FACE_VALUE / 100
            : null;

        $totals = $stock->dividends->pluck('total_dividend_pct')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

        $cashYieldPct = ($latestCashRs !== null && $close > 0) ? ($latestCashRs / $close) * 100 : null;
        $bonusPct = $latest->bonus_share_pct !== null ? (float) $latest->bonus_share_pct : null;

        // The declared Cash/Bonus/Total % columns are all against Rs. 100 face
        // value, not what the stock actually trades at — misleading once price
        // has moved far from face value (e.g. Rs. 539 vs Rs. 100 for NABIL).
        // Bonus shares don't need that face-value conversion: a 6% bonus means
        // 6% more shares, and those extra shares are worth the same current
        // price as the ones already held — so bonus_share_pct is already a
        // real, price-relative % on its own. Only the cash portion needs
        // converting (via dividend_yield_pct above). Total actual return here
        // is that converted cash yield plus the (already price-relative) bonus %.
        $actualTotalYieldPct = ($cashYieldPct !== null || $bonusPct !== null)
            ? round(($cashYieldPct ?? 0) + ($bonusPct ?? 0), 2)
            : null;

        $latestRightShare = $stock->rightShares->first();

        return [
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'company_name' => $stock->company_name,
            'sector' => $stock->sector?->name,
            'close' => $close,
            'latest_fiscal_year' => $latest->fiscal_year,
            'latest_cash_pct' => $latest->cash_dividend_pct !== null ? (float) $latest->cash_dividend_pct : null,
            'latest_bonus_pct' => $bonusPct,
            'latest_total_pct' => $latest->total_dividend_pct !== null ? (float) $latest->total_dividend_pct : null,
            'dividend_yield_pct' => $cashYieldPct !== null ? round($cashYieldPct, 2) : null,
            'actual_total_yield_pct' => $actualTotalYieldPct,
            'years_recorded' => $stock->dividends->count(),
            'avg_total_dividend_pct' => $totals->isNotEmpty() ? round($totals->avg(), 2) : null,
            'latest_signal' => $stock->latestSignal?->signal,
            'history' => $stock->dividends->values(),
            'right_share_count' => $stock->rightShares->count(),
            'latest_right_share_ratio' => $latestRightShare?->ratio,
            'latest_right_share_pct' => $latestRightShare?->percent(),
            'latest_right_share_year' => $latestRightShare?->opening_date?->format('Y') ?? $latestRightShare?->listing_date?->format('Y'),
            'right_share_history' => $stock->rightShares->map(fn ($rs) => [
                'id' => $rs->id,
                'year' => $rs->opening_date?->format('Y') ?? $rs->listing_date?->format('Y'),
                'ratio' => $rs->ratio,
                'pct' => $rs->percent(),
                'issue_price' => $rs->issue_price !== null ? (float) $rs->issue_price : null,
                'opening_date' => $rs->opening_date,
                'closing_date' => $rs->closing_date,
                'listing_date' => $rs->listing_date,
                'status' => $rs->status,
            ])->values(),
        ];
    }

    /**
     * Ranks dividend-paying stocks by a transparent weighted score — trailing
     * yield (50%), payout consistency across recorded years (30%), and
     * historical average total payout (20%) — each normalized against the
     * best value among candidates so no single metric dominates just because
     * of its raw scale. Requires an actual cash yield (bonus-only years don't
     * count as an income pick) and a latest signal that isn't bearish, so
     * this can't recommend a stock currently flagged Sell/Strong Sell.
     */
    private function rankDividendPicks(Collection $rows): Collection
    {
        $candidates = $rows->filter(fn ($r) => ($r['dividend_yield_pct'] ?? 0) > 0
            && $r['latest_signal'] !== 'sell');

        if ($candidates->isEmpty()) {
            return collect();
        }

        $maxYield = $candidates->max('dividend_yield_pct');
        $maxYears = $candidates->max('years_recorded');
        $maxAvg = $candidates->max(fn ($r) => $r['avg_total_dividend_pct'] ?? 0) ?: 1;

        return $candidates->map(function ($r) use ($maxYield, $maxYears, $maxAvg) {
            $yieldScore = $maxYield > 0 ? $r['dividend_yield_pct'] / $maxYield : 0;
            $consistencyScore = $maxYears > 0 ? $r['years_recorded'] / $maxYears : 0;
            $growthScore = ($r['avg_total_dividend_pct'] ?? 0) / $maxAvg;

            $score = round((0.5 * $yieldScore + 0.3 * $consistencyScore + 0.2 * $growthScore) * 100, 1);

            $reasons = [];
            $reasons[] = "{$r['dividend_yield_pct']}% trailing yield";
            $reasons[] = "{$r['years_recorded']} year(s) of recorded payouts";
            if ($r['avg_total_dividend_pct'] !== null) {
                $reasons[] = "averages {$r['avg_total_dividend_pct']}% total dividend historically";
            }
            if ($r['latest_signal']) {
                $reasons[] = 'currently flagged '.str_replace('_', ' ', $r['latest_signal']);
            }

            return [...$r, 'pick_score' => $score, 'reasons' => $reasons];
        })
            ->sortByDesc('pick_score')
            ->take(10)
            ->values();
    }
}
