<?php

namespace App\Services\Reports;

use App\Models\IndexSnapshot;
use Illuminate\Support\Collection;

/** NEPSE index + sub-indices: each one's latest snapshot and close-price history. */
class IndexReportService
{
    public function history(int $days): Collection
    {
        return IndexSnapshot::where('trade_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('trade_date')
            ->get()
            ->groupBy('index_name')
            ->map(fn ($group, $name) => [
                'index_name' => $name,
                'latest' => $group->last(),
                'history' => $group->map(fn ($r) => [
                    'trade_date' => $r->trade_date->toDateString(),
                    'close' => (float) $r->close,
                ])->values(),
            ])
            ->values();
    }
}
