<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves stored media (menu item images) from the configured disk. Only the paths this application writes are
 * served (menu/{menu uuid}/{file uuid}.{ext}); nothing else on the disk is reachable, and no file system
 * location appears in a URL. Immutable file names, so clients may cache for a day.
 */
class MediaController extends Controller
{
    private const PATTERN = '/^menu\/[0-9a-f-]{36}\/[0-9a-f-]{36}\.(jpg|png|webp)$/';

    public function show(string $path): BinaryFileResponse
    {
        if (preg_match(self::PATTERN, $path) !== 1) {
            throw ApiException::notFound('media_not_found', 'This file does not exist.');
        }
        $disk = Storage::disk((string) config('menu.images.disk'));
        if (! $disk->exists($path)) {
            throw ApiException::notFound('media_not_found', 'This file does not exist.');
        }

        return response()->file($disk->path($path), ['Cache-Control' => 'public, max-age=86400, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
