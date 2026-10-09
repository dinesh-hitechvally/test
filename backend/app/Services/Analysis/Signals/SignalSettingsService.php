<?php

namespace App\Services\Analysis\Signals;

use App\Models\SignalSetting;
use App\Models\User;

/**
 * How a person wants BUY / SELL / HOLD decided: the category weights, the minimum % a side needs, the lead margin and
 * the extreme-reading guard. No saved row = the system defaults in config/signals.php, which the cron-generated signals
 * and every report not tied to a person always use.
 *
 * Settings shape: ['weights' => [category => number], 'min_pct' => float, 'margin' => float, 'guard' => bool].
 */
class SignalSettingsService
{
    /** @return array{weights: array<string, int|float>, min_pct: float, margin: float, guard: bool} the system defaults */
    public static function defaults(): array
    {
        return [
            'weights' => config('signals.weights'),
            'min_pct' => (float) config('signals.decision_min_pct'),
            'margin' => (float) config('signals.decision_margin'),
            'guard' => (bool) config('signals.guard_extremes'),
        ];
    }

    /** @return array{weights: array<string, int|float>, min_pct: float, margin: float, guard: bool} */
    public function forUser(?User $user): array
    {
        $row = $user ? SignalSetting::query()->find($user->id) : null;

        if (! $row) {
            return self::defaults();
        }

        $weights = [];
        foreach (SignalConditionScorer::CATEGORIES as $category) {
            $weights[$category] = (int) $row->{"weight_{$category}"};
        }

        return ['weights' => $weights, 'min_pct' => (float) $row->min_pct, 'margin' => (float) $row->margin, 'guard' => $row->guard_extremes];
    }

    /** True when the settings differ from the system defaults (so the stored decisions must be re-applied). */
    public function isCustom(array $settings): bool
    {
        $d = self::defaults();

        foreach (SignalConditionScorer::CATEGORIES as $category) {
            if ((float) ($settings['weights'][$category] ?? 0) !== (float) ($d['weights'][$category] ?? 0)) {
                return true;
            }
        }

        return $settings['min_pct'] !== $d['min_pct'] || $settings['margin'] !== $d['margin'] || $settings['guard'] !== $d['guard'];
    }

    /**
     * @param  array{weights: array<string, int>, min_pct: int, margin: int, guard_extremes: bool}  $input  validated
     */
    public function save(User $user, array $input): array
    {
        $values = ['min_pct' => $input['min_pct'], 'margin' => $input['margin'], 'guard_extremes' => $input['guard_extremes']];
        foreach (SignalConditionScorer::CATEGORIES as $category) {
            $values["weight_{$category}"] = $input['weights'][$category];
        }

        SignalSetting::query()->updateOrCreate(['user_id' => $user->id], $values);

        return $this->present($user);
    }

    /** Back to the system defaults. */
    public function reset(User $user): array
    {
        SignalSetting::query()->whereKey($user->id)->delete();

        return $this->present($user);
    }

    /**
     * What the API returns: the person's settings beside the system defaults.
     *
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $mine = $this->forUser($user);
        $d = self::defaults();

        return [
            'weights' => $this->weightList($mine['weights']),
            'min_pct' => $mine['min_pct'],
            'margin' => $mine['margin'],
            'guard_extremes' => $mine['guard'],
            'is_custom' => $this->isCustom($mine),
            'defaults' => [
                'weights' => $this->weightList($d['weights']),
                'min_pct' => $d['min_pct'],
                'margin' => $d['margin'],
                'guard_extremes' => $d['guard'],
            ],
        ];
    }

    /** @return list<array{category: string, weight: float}> */
    private function weightList(array $weights): array
    {
        return array_map(fn ($c) => ['category' => $c, 'weight' => (float) ($weights[$c] ?? 0)], SignalConditionScorer::CATEGORIES);
    }
}
