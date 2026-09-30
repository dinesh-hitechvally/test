<?php

namespace App\Services\DataQuality;

use App\Models\DailyPrice;
use App\Models\DataQualityFlag;
use App\Models\Dividend;
use App\Models\RightShare;
use App\Models\Stock;
use Illuminate\Support\Carbon;

/**
 * Detects and records data-quality problems — never fixes them (adjusting a
 * price or filling a gap belongs to a human reviewing a `warning`/`critical`
 * flag, or a re-fetch). Per-row checks (OHLC, abnormal change, missing
 * volume, an overwrite of an old value) are cheap and run inline, right
 * where the data lands — see FlagPriceQualityIssues and DailyPriceWriter.
 * Market-wide checks (missing trading dates, an unflagged corporate action)
 * need every stock's data at once, so they run as a daily task instead —
 * see ScanDataQualityTask.
 */
class DataQualityService
{
    // A real move up to NEPSE's circuit band (~10% for most scrips) is normal
    // market behavior; a move well past it is far more likely a data error
    // (a misplaced decimal, a wrong source row) than a real print.
    private const ABNORMAL_CHANGE_PCT = 20.0;

    // Past this many days, a changed value isn't "still settling" anymore —
    // it's a source silently restating an already-closed trading day.
    private const SETTLING_WINDOW_DAYS = 5;

    // A stock missing a date that this share of *other* active stocks has
    // data for is probably a real gap, not a market holiday.
    private const TRADING_CALENDAR_COVERAGE = 0.90;

    // How close a large drop has to land to a known corporate-action date
    // (any of its announcement/distribution/listing/opening/closing dates)
    // to count as "probably that action, not a real loss".
    private const CORPORATE_ACTION_WINDOW_DAYS = 7;

    /** Invalid OHLC: an impossible bar, whatever the cause. */
    public function checkOhlc(DailyPrice $price): void
    {
        $open = (float) $price->open_price;
        $high = (float) $price->high_price;
        $low = (float) $price->low_price;
        $close = (float) $price->close_price;

        if (min($open, $high, $low, $close) <= 0.0) {
            $this->flag($price, 'invalid_ohlc', 'critical', 'A price is zero or negative.');

            return;
        }

        if ($high < $low) {
            $this->flag($price, 'invalid_ohlc', 'critical', "High ({$high}) is below low ({$low}).");

            return;
        }

        if ($open > $high || $open < $low) {
            $this->flag($price, 'invalid_ohlc', 'critical', "Open ({$open}) is outside the day's high/low range ({$low}\u{2013}{$high}).");

            return;
        }

        if ($close > $high || $close < $low) {
            $this->flag($price, 'invalid_ohlc', 'critical', "Close ({$close}) is outside the day's high/low range ({$low}\u{2013}{$high}).");
        }
    }

    /** Abnormal price change: a move well past what a real circuit-limited session allows. */
    public function checkAbnormalChange(DailyPrice $price, ?DailyPrice $previous): void
    {
        if (! $previous || (float) $previous->close_price <= 0.0) {
            return;
        }

        $changePct = (((float) $price->close_price - (float) $previous->close_price) / (float) $previous->close_price) * 100;

        if (abs($changePct) > self::ABNORMAL_CHANGE_PCT) {
            $this->flag($price, 'abnormal_price_change', 'critical', sprintf(
                'Close moved %+.1f%% from the prior day (%s → %s) — well past a real circuit-limited session.',
                $changePct, $previous->close_price, $price->close_price
            ));
        }
    }

    /** Missing volume: a price move with no reported trade behind it. */
    public function checkMissingVolume(DailyPrice $price, ?DailyPrice $previous): void
    {
        if (! $previous || (int) $price->volume > 0) {
            return;
        }

        if ((float) $price->close_price !== (float) $previous->close_price) {
            $this->flag($price, 'missing_volume', 'warning', sprintf(
                'Volume is %s but close moved from %s to %s — a price move implies a trade happened.',
                $price->volume ?? 'null', $previous->close_price, $price->close_price
            ));
        }
    }

    /**
     * A source silently restating a value for a day that should already be
     * settled. Called from DailyPriceWriter right before it overwrites an
     * existing row that differs — same-day/still-settling corrections are
     * normal and not flagged; a change to an older date is.
     */
    public function checkOverwrite(DailyPrice $existing): void
    {
        if ($existing->trade_date->diffInDays(Carbon::today()) <= self::SETTLING_WINDOW_DAYS) {
            return;
        }

        $this->flag($existing, 'corrected_historical_value', 'warning', sprintf(
            '%s already had a stored price (close %s) %d days ago — a source restated it instead of confirming it.',
            $existing->trade_date->toDateString(), $existing->close_price, $existing->trade_date->diffInDays(Carbon::today())
        ));
    }

    /**
     * Missing trading dates: a stock silently skipped on a day most other
     * active stocks got a row for. Builds the trading calendar from the
     * data itself rather than a fixed holiday list, which drifts.
     *
     * @return int flags newly raised
     */
    public function scanMissingTradingDates(int $lookbackDays = 30): int
    {
        $since = Carbon::today()->subDays($lookbackDays)->toDateString();
        $activeStockIds = Stock::where('is_active', true)->pluck('id');
        $totalActive = $activeStockIds->count();

        if ($totalActive === 0) {
            return 0;
        }

        $coverageByDate = DailyPrice::whereIn('stock_id', $activeStockIds)
            ->where('trade_date', '>=', $since)
            ->selectRaw('trade_date, COUNT(DISTINCT stock_id) as covered')
            ->groupBy('trade_date')
            ->get()
            ->filter(fn ($row) => $row->covered / $totalActive >= self::TRADING_CALENDAR_COVERAGE)
            ->pluck('trade_date')
            // Cast to a plain Y-m-d string (pluck() on a model returns the cast Carbon date, same as
            // $existing below produces via toDateString()) so the strict in_array() comparison matches.
            ->map(fn ($date) => $date->toDateString());

        if ($coverageByDate->isEmpty()) {
            return 0;
        }

        $existing = DailyPrice::whereIn('stock_id', $activeStockIds)
            ->where('trade_date', '>=', $since)
            ->get(['stock_id', 'trade_date'])
            ->groupBy('stock_id')
            ->map(fn ($rows) => $rows->pluck('trade_date')->map(fn ($d) => $d->toDateString())->all());

        $flagged = 0;
        foreach ($activeStockIds as $stockId) {
            $stockDates = $existing->get($stockId, []);
            foreach ($coverageByDate as $date) {
                if (! in_array($date, $stockDates, true)) {
                    $this->flagMarketWide($stockId, $date, 'missing_trading_date', 'warning',
                        "No price row for {$date}, though most active stocks have one — likely a scraper gap, not a holiday.");
                    $flagged++;
                }
            }
        }

        return $flagged;
    }

    /**
     * Unadjusted corporate actions: a large drop landing close to a known
     * dividend/right-share date for that stock is very likely the action
     * itself (section 9 of the data architecture doc), not a real loss —
     * flagged as `info` since it's a prompt to review, not an error.
     *
     * @return int flags newly raised
     */
    public function scanUnadjustedCorporateActions(int $lookbackDays = 90, float $dropThresholdPct = 5.0): int
    {
        $since = Carbon::today()->subDays($lookbackDays)->toDateString();
        $flagged = 0;

        $actionDatesByStock = $this->corporateActionDatesByStock();

        Stock::where('is_active', true)->whereIn('id', array_keys($actionDatesByStock))->each(function (Stock $stock) use ($since, $dropThresholdPct, $actionDatesByStock, &$flagged) {
            $prices = $stock->dailyPrices()->where('trade_date', '>=', $since)->orderBy('trade_date')->get();
            $actionDates = $actionDatesByStock[$stock->id] ?? [];

            for ($i = 1; $i < $prices->count(); $i++) {
                $prev = $prices[$i - 1];
                $curr = $prices[$i];
                if ((float) $prev->close_price <= 0.0) {
                    continue;
                }

                $changePct = (((float) $curr->close_price - (float) $prev->close_price) / (float) $prev->close_price) * 100;
                if ($changePct >= -$dropThresholdPct) {
                    continue; // only drops are corporate-action candidates (bonus/rights dilute, they don't inflate)
                }

                $nearAction = collect($actionDates)->contains(
                    fn ($d) => abs(Carbon::parse($d)->diffInDays($curr->trade_date)) <= self::CORPORATE_ACTION_WINDOW_DAYS
                );

                if ($nearAction) {
                    $this->flag($curr, 'unadjusted_corporate_action', 'info', sprintf(
                        'Close dropped %.1f%% on %s, near a known dividend/right-share date for this stock — likely the action itself, not a loss. See the data architecture doc, section 9, for back-adjusting it.',
                        $changePct, $curr->trade_date->toDateString()
                    ));
                    $flagged++;
                }
            }
        });

        return $flagged;
    }

    /** @return array<int, list<string>> stock_id => every date column from its dividends/right-shares rows */
    private function corporateActionDatesByStock(): array
    {
        $dates = [];

        Dividend::whereNotNull('stock_id')->get()->each(function (Dividend $d) use (&$dates) {
            foreach ([$d->announcement_date, $d->distribution_date, $d->bonus_listing_date] as $date) {
                if ($date !== null) {
                    $dates[$d->stock_id][] = $date->toDateString();
                }
            }
        });

        RightShare::whereNotNull('stock_id')->get()->each(function (RightShare $r) use (&$dates) {
            foreach ([$r->opening_date, $r->closing_date, $r->listing_date] as $date) {
                if ($date !== null) {
                    $dates[$r->stock_id][] = $date->toDateString();
                }
            }
        });

        return $dates;
    }

    /** Per-stock flag, deduplicated against an existing unresolved one for the same row and check. */
    private function flag(DailyPrice $price, string $checkType, string $severity, string $message): void
    {
        $this->flagMarketWide($price->stock_id, $price->trade_date->toDateString(), $checkType, $severity, $message);
    }

    private function flagMarketWide(?int $stockId, ?string $tradeDate, string $checkType, string $severity, string $message): void
    {
        $alreadyFlagged = DataQualityFlag::where('stock_id', $stockId)
            ->where('trade_date', $tradeDate)
            ->where('check_type', $checkType)
            ->whereNull('resolved_at')
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        DataQualityFlag::create([
            'stock_id' => $stockId,
            'trade_date' => $tradeDate,
            'check_type' => $checkType,
            'severity' => $severity,
            'message' => $message,
        ]);
    }
}
