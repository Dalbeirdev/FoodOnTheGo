<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 39 — one size limit for every API request body that is not a file upload (uploads are limited by their own
 * validation and by PHP's post_max_size). No API takes more than a few kilobytes of JSON; a body above the limit is
 * refused before it is decoded or validated (413), so a huge or deeply nested document cannot tie up a worker.
 * Provider webhooks keep their own, smaller limit (config/payments.php webhooks.max_bytes).
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('security.max_body_bytes');
        if ($max > 0 && $request->is('api/*') && ! str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'multipart/form-data')) {
            $declared = $request->headers->get('Content-Length');
            if (($declared !== null && (int) $declared > $max) || strlen($request->getContent()) > $max) {
                throw new ApiException(413, 'payload_too_large', 'This request is too large.');
            }
        }

        return $next($request);
    }
}
