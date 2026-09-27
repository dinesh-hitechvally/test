<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavedScreen\StoreSavedScreenRequest;
use Illuminate\Http\Request;

class SavedScreenController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->savedScreens()->latest()->get());
    }

    public function store(StoreSavedScreenRequest $request)
    {
        $screen = $request->user()->savedScreens()->create($request->validated());

        return response()->json($screen, 201);
    }

    public function destroy(Request $request, $id)
    {
        $request->user()->savedScreens()->findOrFail((int) $id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
