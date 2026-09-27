<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\EmailLogService;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    /** Every email the app tried to send, newest first. ?status=sent|logged|failed|sending, ?per_page= */
    public function index(Request $request, EmailLogService $emails)
    {
        return response()->json($emails->recent($request->query('status'), $request->integer('per_page', 50)));
    }
}
