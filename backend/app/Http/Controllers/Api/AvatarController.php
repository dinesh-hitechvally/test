<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UploadAvatarRequest;
use App\Services\Auth\AccountService;
use Illuminate\Http\JsonResponse;

/**
 * The profile picture: a multipart upload, so it stays a plain HTTP route (everything else goes through /graphql).
 */
class AvatarController extends Controller
{
    public function store(UploadAvatarRequest $request, AccountService $account): JsonResponse
    {
        return response()->json(['avatar_url' => $account->storeAvatar($request->user(), $request->file('avatar'))]);
    }

    public function destroy(AccountService $account): JsonResponse
    {
        $account->removeAvatar(request()->user());

        return response()->json(['avatar_url' => null]);
    }
}
