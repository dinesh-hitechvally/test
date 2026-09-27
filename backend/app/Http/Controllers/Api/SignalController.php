<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\Signals\SignalFeedService;
use Illuminate\Http\Request;

class SignalController extends Controller
{
    public function __construct(private readonly SignalFeedService $feed) {}

    /** Dashboard feed: every stock's most recent signal, highest score first. */
    public function today(Request $request)
    {
        return response()->json($this->feed->today($request->query('signal')));
    }

    /** Buy/Sell Signals pages: signals with a price target/stop-loss attached. */
    public function actionable(Request $request)
    {
        return response()->json($this->feed->actionable($request->query('bias') === 'sell' ? 'sell' : 'buy'));
    }
}
