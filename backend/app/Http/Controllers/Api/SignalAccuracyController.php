<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\Signals\SignalAccuracyService;

class SignalAccuracyController extends Controller
{
    public function index(SignalAccuracyService $accuracy)
    {
        return response()->json($accuracy->latestReport());
    }
}
