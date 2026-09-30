<?php

namespace App\Services\Auth;

use App\Auth\Principal;
use App\Models\AccessToken;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues and revokes access tokens (one per signed-in device). Tokens are opaque, stored as SHA-256 by
 * Sanctum, expire per principal type and carry the ability that says what they may be used for.
 */
final class TokenIssuer
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  list<string>  $abilities
     */
    public function issue(Principal $principal, ?string $device = null, array $abilities = [AccessToken::ACCESS]): NewAccessToken
    {
        $ttl = (int) config('auth_security.token_ttl_minutes.'.$principal->principalType()->guard());

        $token = $principal->createToken($this->deviceLabel($device), $abilities, now()->addMinutes($ttl));

        $token->accessToken->forceFill([
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
        ])->save();

        return $token;
    }

    public function revokeAll(Principal $principal, ?int $exceptTokenId = null): int
    {
        return $principal->tokens()->when($exceptTokenId !== null, fn ($query) => $query->whereKeyNot($exceptTokenId))->delete();
    }

    /**
     * A short label the account owner recognises ("web", "android"). Free text from the client is reduced
     * to a safe slug; no device fingerprint is collected.
     */
    private function deviceLabel(?string $device): string
    {
        $label = preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) $device) ?? '';

        return $label === '' ? 'unknown' : mb_substr($label, 0, 60);
    }
}
