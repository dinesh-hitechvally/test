<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScrapeLog;
use Illuminate\Http\Request;

class ScrapeController extends Controller
{
    public function logs(Request $request)
    {
        $limit = (int) $request->query('limit', 20);

        return response()->json(ScrapeLog::latest('created_at')->limit($limit)->get());
    }
}
