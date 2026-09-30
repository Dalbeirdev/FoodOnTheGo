<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * Truecaller one-tap verification (OAuth with PKCE). The Android app obtains an authorization code from the
 * Truecaller SDK; this class exchanges it with Truecaller and returns the phone number Truecaller vouches
 * for. No code is typed and no message is sent, so a sign-in by a Truecaller user costs nothing.
 *
 * The phone number is taken from Truecaller's answer only — never from anything the app sends — so an app
 * cannot claim somebody else's number. Enabled only when TRUECALLER_CLIENT_ID is configured.
 */
final class TruecallerVerifier
{
    public function enabled(): bool
    {
        return (string) config('services.truecaller.client_id') !== '';
    }

    /**
     * @return string the verified phone number in E.164 form
     *
     * @throws ApiException
     */
    public function verifiedPhone(#[SensitiveParameter] string $authorizationCode, #[SensitiveParameter] string $codeVerifier): string
    {
        if (! $this->enabled()) {
            throw ApiException::notFound('truecaller_unavailable', 'Truecaller sign-in is not available.');
        }

        $base = rtrim((string) config('services.truecaller.base_url'), '/');
        $timeout = (int) config('services.truecaller.timeout', 8);

        try {
            $token = Http::timeout($timeout)->asForm()->acceptJson()->post($base.'/v1/token', [
                'grant_type' => 'authorization_code',
                'client_id' => (string) config('services.truecaller.client_id'),
                'code' => $authorizationCode,
                'code_verifier' => $codeVerifier,
            ]);

            $accessToken = $token->successful() ? (string) $token->json('access_token') : '';
            if ($accessToken === '') {
                throw new ApiException(422, 'truecaller_verification_failed', 'We could not verify your number with Truecaller. Please sign in with a code instead.');
            }

            $profile = Http::timeout($timeout)->withToken($accessToken)->acceptJson()->get($base.'/v1/userinfo');
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('truecaller.unreachable', ['exception' => class_basename($e)]);

            throw new ApiException(503, 'truecaller_unreachable', 'Truecaller is not reachable right now. Please sign in with a code instead.');
        }

        $digits = preg_replace('/\D/', '', (string) $profile->json('phone_number')) ?? '';
        $verified = $profile->json('phone_number_verified');

        if (! $profile->successful() || preg_match('/^[1-9]\d{7,14}$/', $digits) !== 1 || $verified === false || $verified === 'false') {
            throw new ApiException(422, 'truecaller_verification_failed', 'We could not verify your number with Truecaller. Please sign in with a code instead.');
        }

        return '+'.$digits;
    }
}
