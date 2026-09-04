<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignalAccuracyStat;

class SignalAccuracyController extends Controller
{
    public function index()
    {
        $latestRun = SignalAccuracyStat::max('computed_at');

        if ($latestRun === null) {
            return response()->json(['available' => false]);
        }

        $stats = SignalAccuracyStat::where('computed_at', $latestRun)->get();

        return response()->json([
            'available' => true,
            'computed_at' => $latestRun,
            'horizon_days' => $stats->first()?->horizon_days,
            'stats' => $stats,
            'disclaimer' => 'Walk-forward backtest over historical signals — real past performance of the rule-based signal engine, not a guarantee of future results.',
        ]);
    }
}
