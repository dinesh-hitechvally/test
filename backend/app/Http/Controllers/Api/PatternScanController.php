<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\Patterns\CandlestickPatternScanner;

class PatternScanController extends Controller
{
    public function index(CandlestickPatternScanner $scanner)
    {
        return response()->json($scanner->scan());
    }
}
