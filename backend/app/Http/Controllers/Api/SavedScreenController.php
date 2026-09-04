<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SavedScreenController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->savedScreens()->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['required', 'array'],
        ]);

        $screen = $request->user()->savedScreens()->create($validated);

        return response()->json($screen, 201);
    }

    public function destroy(Request $request, $id)
    {
        $request->user()->savedScreens()->findOrFail((int) $id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
