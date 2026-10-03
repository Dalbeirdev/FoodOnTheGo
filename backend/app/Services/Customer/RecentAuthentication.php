<?php

namespace App\Services\Customer;

use App\Exceptions\ApiException;
use App\Models\AccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "Recent authentication" for sensitive customer actions (Module 25): changing the phone number, requesting
 * account deletion. A session counts as recent when it was signed in within `customer.reauth.fresh_token_minutes`,
 * or when a code sent to the account's phone was verified on this session within `customer.reauth.window_minutes`
 * (POST /customer/account/reauth + /verify). Otherwise the action answers 403 reauthentication_required and the
 * client runs the re-verification first.
 */
final class RecentAuthentication
{
    public function isRecent(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof AccessToken && $token->created_at !== null && $token->created_at->gte(now()->subMinutes((int) config('customer.reauth.fresh_token_minutes')))) {
            return true;
        }

        return Cache::has($this->key($request));
    }

    public function assert(Request $request): void
    {
        if (! $this->isRecent($request)) {
            throw new ApiException(403, 'reauthentication_required', 'Please confirm it is you first: we will send a code to your phone.');
        }
    }

    /** Called after a reauthentication code was verified on this session. */
    public function mark(Request $request): void
    {
        Cache::put($this->key($request), now()->toIso8601String(), now()->addMinutes((int) config('customer.reauth.window_minutes')));
    }

    private function key(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $id = $token instanceof AccessToken ? 'token:'.$token->getKey() : 'principal:'.$request->user()?->public_id;

        return 'customer:reauth:'.$id;
    }
}
