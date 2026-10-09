<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a stored profile picture. The file name is 40 random hex characters, so the address cannot be guessed;
 * it is public so a plain <img> tag can load it (an image tag cannot send the login token).
 */
class AvatarImageController extends Controller
{
    public function __invoke(string $file): Response
    {
        abort_unless(Storage::disk('local')->exists('avatars/'.$file), 404);

        return Storage::disk('local')->response('avatars/'.$file, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
