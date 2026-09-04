<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;

/**
 * No role/permission system exists yet — every logged-in user has full
 * access to their own data. This just lists registered accounts; it isn't
 * an admin panel with permissions to manage.
 */
class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::select('id', 'name', 'email', 'created_at')->orderBy('created_at')->get());
    }
}
