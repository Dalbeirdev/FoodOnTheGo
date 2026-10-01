<?php

namespace Tests\Feature;

use App\Http\Middleware\SetApiLocale;
use App\Models\AdminUser;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API answers speak the language of the request (Accept-Language): English by default, Hindi when asked.
 * Only texts change — codes, field names and structure are the same in every language.
 */
class ApiMessageLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const HI = ['Accept-Language' => 'hi-IN,hi;q=0.9,en;q=0.5'];

    /**
     * Every message the API can show a person, read from the source: texts of ApiException, field messages,
     * confirmations, the phone and geometry checks, and the renderer's generic texts.
     *
     * @return list<string>
     */
    public static function sourceMessages(): array
    {
        $found = [];
        $string = "'((?:[^'\\\\]|\\\\.)+)'";
        $patterns = [
            "/ApiException(?:::\\w+)?\\((?:\\d+,\\s*)?'[a-z_]+',\\s*{$string}/",          // new ApiException(422, 'code', 'Text') / ::conflict('code', 'Text')
            "/=>\\s*\\[{$string}\\]/",                                                      // withMessages(['field' => ['Text']])
            "/__\\({$string}/",                                                            // __('Text', [...])
            "/self::invalid\\({$string}\\)/", "/\\?\\s*{$string}\\s*:\\s*{$string}\\)/",   // geometry
            "/InvalidArgumentException\\({$string}\\)/",                                   // phone
            "/^\\s+\\d{3} => {$string},$/m",                                               // renderer: generic texts per status
            "/'(?:validation_failed|internal_error)', {$string}/",
        ];
        $files = [...glob(app_path('Http/Controllers/Api/*/*.php')), ...glob(app_path('Http/Middleware/*.php')), ...glob(app_path('Http/Support/*.php')), ...glob(app_path('Http/Requests/*/*.php')),
            ...glob(app_path('Services/*/*.php')), ...glob(app_path('Exceptions/*.php')), app_path('Models/Concerns/HasSpatialColumns.php'), app_path('Support/Geo/GeoJsonGeometry.php'), app_path('Support/PhoneNumber.php')];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach (array_slice($matches, 1) as $group) {
                    foreach ($group as $text) {
                        $text = stripslashes($text);
                        // A sentence a person reads: has a space and a capital first letter; not a translation key or a code.
                        if (str_contains($text, ' ') && preg_match('/^[A-Z]/', $text) === 1 && ! str_contains($text, '::')) {
                            $found[$text] = true;
                        }
                    }
                }
            }
        }
        $found['The request could not be completed.'] = true;
        $found['Something went wrong on our side.'] = true;
        ksort($found);

        return array_keys($found);
    }

    public function test_every_message_in_the_source_has_a_hindi_translation_with_the_same_placeholders(): void
    {
        $hi = json_decode((string) file_get_contents(lang_path('hi.json')), true, flags: JSON_THROW_ON_ERROR);
        $messages = self::sourceMessages();
        $this->assertGreaterThan(80, count($messages));

        $missing = array_values(array_filter($messages, fn (string $m): bool => ! isset($hi[$m])));
        $this->assertSame([], $missing, 'Add these to lang/hi.json');

        foreach ($hi as $english => $hindi) {
            preg_match_all('/:[a-z]+/', $english, $expected);
            preg_match_all('/:[a-z]+/', $hindi, $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $english);
            $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $hindi, $english);
        }
        $this->assertSame([], array_values(array_diff(array_keys($hi), $messages)), 'lang/hi.json has texts the source no longer uses');

        // A message built with values keeps the values (codes stay codes).
        $this->assertSame('स्थिति ACTIVE से INVITED नहीं बदली जा सकती।', __('Status cannot change from :from to :to.', ['from' => 'ACTIVE', 'to' => 'INVITED'], 'hi'));
        $this->assertSame('Status cannot change from ACTIVE to INVITED.', __('Status cannot change from :from to :to.', ['from' => 'ACTIVE', 'to' => 'INVITED'], 'en'));
    }

    public function test_hindi_validation_messages_have_the_same_keys_and_placeholders_as_english(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $hi = require lang_path('hi/validation.php');
        $flat = function (array $a, string $prefix = '') use (&$flat): array {
            $out = [];
            foreach ($a as $k => $v) {
                $out += is_array($v) ? $flat($v, $prefix.$k.'.') : [$prefix.$k => (string) $v];
            }

            return $out;
        };
        $enFlat = array_filter($flat($en), fn (string $k): bool => ! str_starts_with($k, 'attributes.'), ARRAY_FILTER_USE_KEY);
        $hiFlat = array_filter($flat($hi), fn (string $k): bool => ! str_starts_with($k, 'attributes.'), ARRAY_FILTER_USE_KEY);

        $this->assertSame(array_keys($enFlat), array_keys($hiFlat));
        foreach ($enFlat as $key => $english) {
            preg_match_all('/:[a-z_-]+/', $english, $expected);
            preg_match_all('/:[a-z_-]+/', $hiFlat[$key], $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $key);
            $this->assertSame(substr_count($english, '|'), substr_count($hiFlat[$key], '|'), $key);
        }
        $this->assertSame('ई-मेल', $hi['attributes']['email']);
    }

    public function test_answers_are_english_by_default_and_hindi_when_the_request_asks_for_it(): void
    {
        $this->assertSame(['en', 'hi'], SetApiLocale::available());
        $admin = AdminUser::factory()->create();
        $wrong = ['email' => $admin->email, 'password' => 'not-the-password'];

        $this->postJson('/api/v1/auth/admin/login', $wrong)->assertUnauthorized()->assertHeader('Content-Language', 'en')
            ->assertJsonPath('error.message', 'The e-mail or password is incorrect.');
        $this->postJson('/api/v1/auth/admin/login', $wrong, self::HI)->assertUnauthorized()->assertHeader('Content-Language', 'hi')
            ->assertJsonPath('error.code', 'invalid_credentials')->assertJsonPath('error.message', 'ई-मेल या पासवर्ड गलत है।');
        // A language without a translation, or nonsense in the header: English.
        $this->postJson('/api/v1/auth/admin/login', $wrong, ['Accept-Language' => 'fr-FR,de;q=0.8'])->assertJsonPath('error.message', 'The e-mail or password is incorrect.');
        $this->getJson('/api/v1/auth/me', ['Accept-Language' => '../../etc/passwd'])->assertUnauthorized()->assertHeader('Content-Language', 'en')->assertJsonPath('error.message', 'Authentication is required.');
        // The language of one request does not leak into the next one.
        $this->getJson('/api/v1/auth/me', self::HI)->assertJsonPath('error.message', 'साइन इन करना ज़रूरी है।');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('error.message', 'Authentication is required.');
    }

    public function test_validation_generic_and_confirmation_texts_follow_the_language(): void
    {
        // Laravel's own validation rules, with the field named in Hindi.
        $response = $this->postJson('/api/v1/auth/admin/login', ['email' => 'not-an-email'], self::HI)->assertUnprocessable();
        $response->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.message', 'भेजी गई जानकारी सही नहीं है।');
        $fields = $response->json('error.details.fields');
        $this->assertSame(['email', 'password'], array_keys($fields));   // field names never change
        $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $fields['email'][0]);
        $this->assertStringContainsString('पासवर्ड', $fields['password'][0]);
        $this->assertStringNotContainsString('validation.', json_encode($fields, JSON_UNESCAPED_UNICODE));

        // Generic texts of the renderer.
        $this->getJson('/api/v1/auth/me', self::HI)->assertUnauthorized()->assertJsonPath('error.message', 'साइन इन करना ज़रूरी है।');
        $this->getJson('/api/v1/no-such-endpoint', self::HI)->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertJsonPath('error.message', 'मांगी गई चीज़ नहीं मिली।');

        // A confirmation, and a field message written by the application.
        $admin = AdminUser::factory()->create();
        $this->postJson('/api/v1/auth/admin/password/forgot', ['email' => $admin->email], self::HI)->assertOk()
            ->assertJsonPath('message', 'अगर इस ई-मेल से कोई खाता है, तो रीसेट लिंक भेज दिया गया है।');
        $headers = $this->bearer($admin) + self::HI;
        $this->postJson('/api/v1/auth/password', ['current_password' => 'wrong-wrong-wrong', 'password' => 'a-long-new-passphrase', 'password_confirmation' => 'a-long-new-passphrase'], $headers)
            ->assertUnprocessable()->assertJsonPath('error.details.fields.current_password.0', 'मौजूदा पासवर्ड सही नहीं है।');

        // A refusal of the application.
        $secret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->assertOk()->json('secret');
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => '000000'], $headers)->assertUnprocessable()->assertJsonPath('error.message', 'यह कोड सही नहीं है।');
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => Totp::codeAt($secret, now()->getTimestamp())], $headers)->assertOk();
    }
}
