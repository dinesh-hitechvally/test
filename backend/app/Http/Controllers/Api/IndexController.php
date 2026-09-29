<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\IndexReportService;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function index(Request $request, IndexReportService $indices)
    {
        return response()->json($indices->history($request->integer('days', 90)));
    }
}
