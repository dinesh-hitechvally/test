<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    /**
     * Dashboard feed: every stock's most recent signal, newest-scored first.
     */
    public function today(Request $request)
    {
        $stocks = Stock::query()
            ->with(['latestSignal', 'latestPrice'])
            ->whereHas('latestSignal')
            ->get();

        if ($filter = $request->query('signal')) {
            $stocks = $stocks->filter(fn ($s) => $s->latestSignal?->signal === $filter);
        }

        $sorted = $stocks->sortByDesc(fn ($s) => (float) $s->latestSignal?->score)->values();

        return response()->json($sorted);
    }
}
