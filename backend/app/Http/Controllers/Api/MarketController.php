<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\ScreenerService;

class MarketController extends Controller
{
    public function __construct(private readonly ScreenerService $screener) {}

    public function screener()
    {
        return response()->json($this->screener->screener());
    }

    public function fiftyTwoWeek()
    {
        return response()->json($this->screener->fiftyTwoWeek());
    }
}
