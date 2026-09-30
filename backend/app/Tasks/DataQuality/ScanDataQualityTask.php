<?php

namespace App\Tasks\DataQuality;

use App\Services\DataQuality\DataQualityService;
use App\Tasks\Task;

/**
 * The market-wide checks (missing trading dates, an unflagged corporate
 * action) that need every stock's data at once — the per-row checks
 * (invalid OHLC, abnormal change, missing volume, a stale-date correction)
 * already run inline as prices land; see FlagPriceQualityIssues and
 * DailyPriceWriter.
 */
class ScanDataQualityTask extends Task
{
    public function __construct(private readonly DataQualityService $quality) {}

    public function handle(): string
    {
        $missingDates = $this->quality->scanMissingTradingDates();
        $corporateActions = $this->quality->scanUnadjustedCorporateActions();

        return "Flagged {$missingDates} missing trading date(s), {$corporateActions} likely-unadjusted corporate action(s).";
    }
}
