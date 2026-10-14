<?php

namespace Tests\Feature\Security;

use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Module 39 — the configuration side of the hardening: what a deployment gets when it sets nothing (fake providers
 * refused, four-eyes and administrator MFA on), the security:check gate, CORS for a bearer-only API, the headers and
 * the error answers, and the log redaction of free text.
 */
class HardeningConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reads a config file as an installation with these environment values would.
     *
     * @param  array<string, string|null>  $env  null: the variable is not set (phpunit.xml forces some for the suite)
     * @return array<string, mixed>
     */
    private function configIn(string $file, array $env): array
    {
        $before = [];
        foreach ($env as $name => $value) {
            $before[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        try {
            return require base_path("config/{$file}.php");
        } finally {
            foreach ($before as $name => [$put, $e, $s]) {
                $put === false ? putenv($name) : putenv("{$name}={$put}");
                $_ENV[$name] = $e;
                $_SERVER[$name] = $s;
                if ($e === null) {
                    unset($_ENV[$name]);
                }
                if ($s === null) {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    public function test_a_deployment_that_sets_nothing_gets_the_safe_defaults(): void
    {
        foreach (['production', 'staging', 'Production', 'prod'] as $env) {
            // Even an explicit "allow fake" cannot switch a fake provider on outside local / testing.
            $this->assertFalse($this->configIn('payments', ['APP_ENV' => $env, 'PAYMENT_ALLOW_FAKE_PROVIDER' => 'true'])['providers']['allow_fake'], "payments fake in {$env}");
            $this->assertFalse($this->configIn('journey', ['APP_ENV' => $env, 'JOURNEY_ALLOW_FAKE_PROVIDERS' => 'true'])['providers']['allow_fake'], "journey fake in {$env}");
            $this->assertTrue($this->configIn('refunds', ['APP_ENV' => $env])['policy']['four_eyes'], "refund four-eyes in {$env}");
            $finance = $this->configIn('finance', ['APP_ENV' => $env, 'FINANCE_SETTLEMENT_FOUR_EYES' => null, 'FINANCE_PAYOUT_FOUR_EYES' => null, 'FINANCE_ADJUSTMENT_FOUR_EYES' => null]);
            $this->assertTrue($finance['settlements']['four_eyes'] && $finance['payouts']['four_eyes'] && $finance['adjustments']['four_eyes'], "finance four-eyes in {$env}");
            $auth = $this->configIn('auth_security', ['APP_ENV' => $env, 'AUTH_MFA_REQUIRED_ADMIN' => null]);
            $this->assertTrue($auth['mfa']['required']['admin'], "admin MFA in {$env}");
            $this->assertGreaterThan(0, $auth['admin_idle_minutes']);
        }
        // Local development keeps its fake providers, and can switch them off.
        $this->assertTrue($this->configIn('payments', ['APP_ENV' => 'local'])['providers']['allow_fake']);
        $this->assertFalse($this->configIn('payments', ['APP_ENV' => 'local', 'PAYMENT_ALLOW_FAKE_PROVIDER' => 'false'])['providers']['allow_fake']);
        // The private disk is never served over HTTP; the API is bearer-only.
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertSame(['api/*'], config('cors.paths'));
        $this->assertFalse(config('sanctum.routes'));
    }

    public function test_security_check_reports_locally_and_fails_when_judged_as_production(): void
    {
        config(['database.redis.default.password' => 'redis-password-that-must-never-print']);
        $this->assertSame(0, Artisan::call('security:check', ['--json' => true]));
        $local = json_decode(Artisan::output(), true);
        $this->assertSame('testing', $local['environment']);
        $this->assertFalse($local['judged_as_production']);
        $this->assertGreaterThan(25, $local['total']);
        $this->assertContains('fake payment provider is refused', $local['failed']);

        $this->assertSame(1, Artisan::call('security:check', ['--as-production' => true]));
        $text = Artisan::output();
        $this->assertStringContainsString('FAIL  fake payment provider is refused', $text);
        $this->assertStringNotContainsString('redis-password-that-must-never-print', $text);
        $this->assertStringNotContainsString((string) config('app.key'), $text);

        // A configuration made safe passes every item a configuration can fix; what is left is the registration of
        // development routes and packages, which only a production build (APP_ENV, composer --no-dev) removes.
        config([
            'app.debug' => false, 'app.url' => 'https://api.foodonthego.example', 'security.trusted_hosts' => ['^api\.foodonthego\.example$'],
            'session.secure' => true, 'session.http_only' => true, 'cors.allowed_origins' => ['https://www.foodonthego.example'], 'api.readiness.public' => false,
            'database.connections.pgsql.sslmode' => 'verify-full', 'mail.default' => 'smtp', 'services.sms.driver' => 'msg91', 'notifications.channels.PUSH.driver' => 'fcm',
            'payments.providers.allow_fake' => false, 'journey.providers.allow_fake' => false, 'finance.payouts.driver' => 'razorpayx', 'auth_security.mfa.required.admin' => true,
            'refunds.policy.four_eyes' => true, 'finance.settlements.four_eyes' => true, 'finance.payouts.four_eyes' => true, 'finance.adjustments.four_eyes' => true,
            'payments.providers.allow_live' => false, 'reverb.apps.apps.0.allowed_origins' => ['www.foodonthego.example'],
        ]);
        Artisan::call('security:check', ['--json' => true, '--as-production' => true]);
        $safe = json_decode(Artisan::output(), true);
        $this->assertEqualsCanonicalizing(['no development routes are registered (/dev, _boost)', 'development packages are not installed (laravel/boost)'], $safe['failed']);
    }

    public function test_the_audit_purge_switch_of_a_session_is_reported(): void
    {
        DB::statement("set fotg.audit_purge = 'on'");
        Artisan::call('security:check', ['--json' => true]);
        $this->assertContains('audit purge is not enabled for this database session', json_decode(Artisan::output(), true)['failed']);
        DB::statement("set fotg.audit_purge = 'off'");
        Artisan::call('security:check', ['--json' => true]);
        $this->assertNotContains('audit purge is not enabled for this database session', json_decode(Artisan::output(), true)['failed']);
    }

    public function test_cors_answers_only_configured_origins_and_never_allows_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://www.foodonthego.example']]);
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/restaurants', server: ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization']);

        $evil = $preflight('https://evil.example');
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($evil->headers->get('Access-Control-Allow-Credentials'));
        foreach (['null', 'https://www.foodonthego.example.evil.example', 'http://www.foodonthego.example'] as $lookalike) {
            $this->assertNotSame($lookalike, $preflight($lookalike)->headers->get('Access-Control-Allow-Origin'), $lookalike);
        }

        $good = $preflight('https://www.foodonthego.example');
        $this->assertSame('https://www.foodonthego.example', $good->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($good->headers->get('Access-Control-Allow-Credentials'));
        $this->assertStringNotContainsStringIgnoringCase('cookie', (string) $good->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_every_kind_of_api_answer_carries_the_security_headers_and_no_server_detail(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::get('/__m39/boom', fn () => throw new \RuntimeException('internal detail at /var/www/secret/path.php'));
            Route::get('/__m39/sql', fn () => DB::select("select * from m39_no_such_table where phone = '+919876543210'"));
        });

        $answers = [
            200 => $this->getJson('/api/v1/health'),
            401 => $this->getJson('/api/v1/auth/me'),
            404 => $this->getJson('/api/v1/no-such-route'),
            405 => $this->deleteJson('/api/v1/health'),
            422 => $this->postJson('/api/v1/auth/admin/login', []),
            500 => $this->getJson('/api/v1/__m39/boom'),
        ];
        foreach ($answers as $status => $response) {
            $response->assertStatus($status);
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$status} Cache-Control");
            $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'), "{$status} is JSON");
            $this->assertNotNull($response->json('error') ?? $response->json('status'), "{$status} has the standard shape");
        }

        foreach ([$answers[500], $this->getJson('/api/v1/__m39/sql')->assertStatus(500)] as $crash) {
            $body = $crash->getContent();
            foreach (['internal detail', '/var/www', 'SQLSTATE', 'm39_no_such_table', '9876543210', 'trace', 'exception', '.php'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $body, "a 500 answer must not carry '{$leak}'");
            }
            $this->assertNotNull($crash->json('error.code'));
        }
    }

    public function test_malformed_and_wrongly_typed_input_is_refused_never_a_crash(): void
    {
        $this->call('POST', '/api/v1/auth/admin/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"email": "a@b.c", "password":')
            ->assertStatus(422);
        $cases = [
            ['POST', '/api/v1/auth/admin/login', ['email' => ['a@b.c'], 'password' => ['x']]],
            ['POST', '/api/v1/auth/restaurant/login', ['email' => ['$ne' => ''], 'password' => ['$gt' => '']]],
            ['POST', '/api/v1/auth/customer/otp/request', ['phone' => ['+919876543210']]],
            ['POST', '/api/v1/auth/customer/otp/verify', ['phone' => ['x'], 'challenge_id' => ['y'], 'code' => ['z']]],
            ['POST', '/api/v1/auth/admin/password/forgot', ['email' => ['a@b.c']]],
            ['POST', '/api/v1/auth/admin/mfa/verify', ['mfa_challenge' => ['x'], 'code' => ['000000']]],
            ['GET', '/api/v1/restaurants?q[]=x&city[]=y&page[]=1', []],
            ['GET', '/api/v1/restaurants?per_page=100000000000000000000', []],
            ['POST', '/api/v1/analytics/events', ['events' => 'not-a-list']],
        ];
        $wrong = [];
        foreach ($cases as [$method, $url, $body]) {
            $status = $this->json($method, $url, $body)->status();
            if ($status >= 500) {
                $wrong[] = "{$method} {$url} → {$status}";
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_an_oversized_request_body_is_refused_before_it_is_read_and_uploads_keep_their_own_limit(): void
    {
        config(['security.max_body_bytes' => 4096]);
        $big = json_encode(['email' => 'a@b.example', 'password' => str_repeat('x', 5000)]);
        $this->call('POST', '/api/v1/auth/admin/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $big)
            ->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large')->assertHeader('X-Content-Type-Options', 'nosniff');
        // A deeply nested document is refused the same way, before it is decoded.
        $nested = str_repeat('{"a":', 3000).'1'.str_repeat('}', 3000);
        $this->call('POST', '/api/v1/analytics/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $nested)->assertStatus(413);
        // A small body passes the limit (and is then refused or accepted by the endpoint itself).
        $this->postJson('/api/v1/auth/admin/login', ['email' => 'nobody@foodonthego.example', 'password' => 'short-password'])->assertUnauthorized();
        // A webhook keeps its own, smaller limit.
        $this->assertLessThan(262144, (int) config('payments.webhooks.max_bytes', 65536));
        $this->assertSame(262144, (int) $this->configIn('security', ['SECURITY_MAX_BODY_BYTES' => null])['max_body_bytes']);
    }

    public function test_free_text_in_logs_loses_tokens_sql_values_phone_numbers_e_mails_and_one_time_links(): void
    {
        $production = new SensitiveDataRedactor(['password', 'token', 'secret'], maskOneTimeLinks: true);
        $local = new SensitiveDataRedactor(['password', 'token', 'secret']);

        $text = $production->redactMessage('Bearer 12|abcDEF123456789 failed for +919876543210 / priya.sharma@example.in; '
            .'SQLSTATE[23505]: Unique violation: Key (phone_e164)=(+919876543210) already exists (Connection: pgsql, SQL: insert into customers values (\'+919876543210\'))');
        foreach (['abcDEF123456789', '9876543210', 'priya.sharma', "'+91"] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('+********3210', $text);
        $this->assertStringContainsString('***@example.in', $text);
        $this->assertStringContainsString('SQLSTATE[23505]', $text, 'the error class stays readable');

        $link = 'https://admin.foodonthego.example/reset-password?token=Zk3m9QpL0aB7cD2eF4gH6iJ8kLmN0pQr&email=x';
        $this->assertStringNotContainsString('Zk3m9QpL0aB7cD2eF4gH6iJ8kLmN0pQr', $production->redactText($link));
        $this->assertStringContainsString('Zk3m9QpL0aB7cD2eF4gH6iJ8kLmN0pQr', $local->redactText($link), 'local development keeps its links usable');

        $this->assertSame(['password' => SensitiveDataRedactor::MASK, 'nested' => ['push_token' => SensitiveDataRedactor::MASK, 'note' => 'Bearer '.SensitiveDataRedactor::MASK]],
            $production->redact(['password' => 'hunter2', 'nested' => ['push_token' => 'fcm-token', 'note' => 'Bearer abc.def']]));
    }
}
