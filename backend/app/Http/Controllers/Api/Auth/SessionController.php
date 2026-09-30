<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\Principal;
use App\Enums\SecurityEventType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\PrincipalResource;
use App\Http\Resources\Auth\SessionResource;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Identity and sessions of whoever is signed in — the same endpoints for all three principal types.
 * Every query starts from the authenticated principal's own tokens, so another account's session cannot be
 * read or revoked by guessing an id.
 */
class SessionController extends Controller
{
    public function me(Request $request): PrincipalResource
    {
        return new PrincipalResource($request->user());
    }

    public function logout(Request $request, SecurityEventRecorder $events): JsonResponse
    {
        /** @var Principal $principal */
        $principal = $request->user();
        $token = $principal->currentAccessToken();

        $events->record(SecurityEventType::Logout, $principal, ['session' => $token->public_id ?? null]);
        $principal->tokens()->whereKey($token->getKey())->delete();

        return response()->json(null, 204);
    }

    public function logoutAll(Request $request, TokenIssuer $tokens, SecurityEventRecorder $events): JsonResponse
    {
        /** @var Principal $principal */
        $principal = $request->user();
        $revoked = $tokens->revokeAll($principal);

        $events->record(SecurityEventType::SessionRevoked, $principal, ['scope' => 'all', 'sessions_revoked' => $revoked]);

        return response()->json(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = $request->user()->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('last_used_at')->latest('id')->get();

        return SessionResource::collection($sessions);
    }

    /**
     * Revokes one of the caller's own sessions. An id that belongs to someone else is answered exactly
     * like an id that does not exist (404), so session ids cannot be probed.
     */
    public function destroy(Request $request, string $session, SecurityEventRecorder $events): JsonResponse
    {
        /** @var Principal $principal */
        $principal = $request->user();
        $deleted = $principal->tokens()->where('public_id', $session)->delete();

        if ($deleted === 0) {
            throw ApiException::notFound('session_not_found', 'This session does not exist or has already ended.');
        }

        $events->record(SecurityEventType::SessionRevoked, $principal, ['scope' => 'one', 'session' => $session]);

        return response()->json(null, 204);
    }
}
