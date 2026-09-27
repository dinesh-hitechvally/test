<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Portfolio\PriceAlertService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /** Holdings past their stop-loss/target, and watchlist stocks past their alert price. */
    public function index(Request $request, PriceAlertService $alerts)
    {
        return response()->json($alerts->forUser($request->user()));
    }
}
