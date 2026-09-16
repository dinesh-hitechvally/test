<?php

namespace App\Console\Commands;

use App\Models\ScrapeLog;
use App\Services\MarketData\NepalStockSecurityResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Separate from market:sync on purpose: market:sync only ever creates a
 * stock as a byproduct of seeing it trade that day, so an illiquid,
 * suspended, or brand-new listing that hasn't traded yet would otherwise
 * never show up in this app. This walks the official, complete securities
 * list instead, so every real NEPSE listing gets a row regardless of
 * today's trading activity.
 */
#[Signature('stocks:sync-list')]
#[Description('Create a stocks row for every security on the official nepalstock.com list, not just ones that traded today')]
class SyncStockList extends Command
{
    public function handle(NepalStockSecurityResolver $resolver): int
    {
        $this->info('Fetching the full securities list from nepalstock.com...');

        try {
            $result = $resolver->syncAllSecurities();
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            ScrapeLog::create([
                'source' => 'nepalstock.com/security-list',
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d securities on record — %d new stock(s) created, %d already existed.',
            $result['total'],
            $result['created'],
            $result['existing']
        ));

        ScrapeLog::create([
            'source' => 'nepalstock.com/security-list',
            'status' => 'success',
            'records_processed' => $result['total'],
            'message' => "{$result['created']} new stock(s) created, {$result['existing']} already existed.",
        ]);

        return self::SUCCESS;
    }
}
