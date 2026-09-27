<?php

namespace App\Services\Analysis\Signals;

use App\Models\Stock;
use App\Services\Reports\PriceStatisticsService;
use Illuminate\Support\Collection;

/**
 * The Rule Scanner page: which stocks' latest signal fired the chosen rules.
 * Reads the already-stored rule_keys — nothing is recomputed live.
 */
class SignalRuleScanner
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

    /** Every rule the scanner can filter on. */
    public function rules(): Collection
    {
        return collect(SignalRules::RULES)->map(fn ($r, $key) => [
            'key' => $key,
            'label' => $r['label'],
            'direction' => $r['direction'],
        ])->values();
    }

    /**
     * @param  string[]  $keys
     * @return string[] the keys that aren't real rules
     */
    public function unknownKeys(array $keys): array
    {
        return array_values(array_filter($keys, fn ($key) => ! SignalRules::isValidKey($key)));
    }

    /**
     * Stocks whose latest signal fired at least one (mode "any") or all
     * (mode "all") of $rules, most matched rules first.
     *
     * @param  string[]  $rules
     */
    public function scan(array $rules, string $mode): array
    {
        $requested = array_values(array_unique($rules));
        $changes = $this->prices->priceChanges();

        $matches = Stock::with(['sector', 'latestSignal', 'latestPrice'])->get()
            ->filter(function ($stock) use ($requested, $mode) {
                $fired = $stock->latestSignal?->rule_keys ?? [];

                return $mode === 'all'
                    ? count(array_diff($requested, $fired)) === 0
                    : count(array_intersect($requested, $fired)) > 0;
            })
            ->map(function ($stock) use ($changes, $requested) {
                $matchedKeys = array_values(array_intersect($requested, $stock->latestSignal?->rule_keys ?? []));

                return [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'sector' => $stock->sector?->name,
                    'close' => $stock->latestPrice?->close_price,
                    'change_pct' => $changes->get($stock->id)['change_pct'] ?? null,
                    'signal' => $stock->latestSignal?->signal,
                    'trade_date' => $stock->latestSignal?->trade_date,
                    'matched_rules' => array_map(fn ($key) => ['key' => $key, 'label' => SignalRules::label($key)], $matchedKeys),
                ];
            })
            ->sortByDesc(fn ($row) => count($row['matched_rules']))
            ->values();

        return [
            'mode' => $mode,
            'requested_rules' => $requested,
            'matched_count' => $matches->count(),
            'stocks' => $matches,
        ];
    }
}
