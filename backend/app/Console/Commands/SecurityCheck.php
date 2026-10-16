<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Boost\BoostServiceProvider;

/**
 * Module 39 — the automated part of the production security gate. Reads the configuration of THIS installation and
 * says, item by item, whether it is fit for production. Outside local / testing a failed item makes the command fail
 * (exit 1): a deployment runs it before traffic is let in. Locally it reports what production must change, and passes.
 *
 * It prints names and verdicts only — never a value of a secret.
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check {--json : machine-readable output} {--as-production : judge this installation as if it were production}';

    protected $description = 'Check that the configuration of this installation is safe for production (Module 39 gate)';

    public function handle(): int
    {
        $env = (string) config('app.env');
        $strict = $this->option('as-production') || ! in_array($env, ['local', 'testing'], true);
        $appUrl = (string) config('app.url');
        $checks = [
            ['APP_DEBUG is off', ! (bool) config('app.debug')],
            ['APP_KEY is set', strlen((string) config('app.key')) >= 32],
            ['APP_URL is https', str_starts_with($appUrl, 'https://')],
            ['trusted hosts are configured (APP_URL / TRUSTED_HOSTS)', count((array) config('security.trusted_hosts')) > 0],
            ['session cookie is Secure', (bool) config('session.secure')],
            ['session cookie is HttpOnly', (bool) config('session.http_only')],
            ['CORS: no wildcard origin and no credentials', ! in_array('*', (array) config('cors.allowed_origins'), true) && ! (bool) config('cors.supports_credentials')],
            ['realtime: no wildcard or localhost origin (REVERB_ALLOWED_ORIGINS)', collect((array) config('reverb.apps.apps.0.allowed_origins'))->every(fn ($o): bool => $o !== '*' && ! preg_match('#^(localhost|127\.0\.0\.1|\[::1\])$#', (string) $o))],
            ['CORS: no localhost origin', collect((array) config('cors.allowed_origins'))->every(fn ($o): bool => ! preg_match('#//(localhost|127\.0\.0\.1|\[::1\])#', (string) $o))],
            ['readiness endpoint is not public', ! (bool) config('api.readiness.public')],
            ['database connection requires TLS (DB_SSLMODE require / verify-*)', in_array((string) config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true)],
            ['Redis has a password', (string) config('database.redis.default.password') !== ''],
            ['mail is a real provider (set; not log / array)', ! in_array((string) config('mail.default'), ['log', 'array', ''], true)],
            ['SMS is a real provider (not log / fake)', ! in_array((string) config('services.sms.driver'), ['log', 'fake', ''], true)],
            ['push is FCM (not the fake provider)', strtolower((string) config('notifications.channels.PUSH.driver')) === 'fcm'],
            ['fake payment provider is refused', ! (bool) config('payments.providers.allow_fake')],
            ['fake journey providers are refused', ! (bool) config('journey.providers.allow_fake')],
            ['payout provider is not the fake one', strtolower((string) config('finance.payouts.driver')) !== 'fake'],
            ['administrator MFA is required', (bool) config('auth_security.mfa.required.admin')],
            ['four-eyes: refunds', (bool) config('refunds.policy.four_eyes')],
            ['four-eyes: settlements', (bool) config('finance.settlements.four_eyes')],
            ['four-eyes: payouts', (bool) config('finance.payouts.four_eyes')],
            ['four-eyes: manual adjustments', (bool) config('finance.adjustments.four_eyes')],
            ['administrator idle timeout is on', (int) config('auth_security.admin_idle_minutes') > 0],
            ['request body limit is on', (int) config('security.max_body_bytes') > 0],
            ['private disk is not served over HTTP', ! (bool) config('filesystems.disks.local.serve')],
            ['no development routes are registered (/dev, _boost)', collect(Route::getRoutes()->getRoutes())->every(fn ($r): bool => ! str_starts_with($r->uri(), 'api/v1/dev/') && ! str_starts_with($r->uri(), '_boost'))],
            ['no default /broadcasting/auth web route', collect(Route::getRoutes()->getRoutes())->every(fn ($r): bool => $r->uri() !== 'broadcasting/auth')],
            ['development packages are not installed (laravel/boost)', ! class_exists(BoostServiceProvider::class)],
            ['audit and security trails are append-only (database triggers present)', $this->appendOnlyTriggers()],
            ['audit purge is not enabled for this database session', $this->auditPurgeOff()],
            ['live payments stay off until a deliberate change (Module 30)', ! (bool) config('payments.providers.allow_live')],
            // Module 41 (final readiness review): what a deployment could still get wrong after the items above.
            ['TEST tax / fee rules are refused', ! (bool) config('checkout.financial_rules.allow_test')],
            ['fake notification providers are refused (push / e-mail / SMS)', ! (bool) config('notifications.allow_fake')],
            ['places and routing use the live provider (PLACES_DRIVER / ROUTING_DRIVER = google)', strtolower((string) config('services.places.driver')) === 'google' && strtolower((string) config('services.routing.driver')) === 'google'],
            ['FCM credentials are present (FIREBASE_PROJECT_ID, readable FIREBASE_CREDENTIALS file)', (string) config('notifications.fcm.project_id') !== '' && is_readable((string) config('notifications.fcm.credentials'))],
            ['web origins are set and https only (FRONTEND_URLS / FRONTEND_URL)', ($origins = (array) config('cors.allowed_origins')) !== [] && collect($origins)->every(fn ($o): bool => str_starts_with((string) $o, 'https://')) && str_starts_with((string) config('app.frontend_url'), 'https://')],
            ['trusted proxies are configured (TRUSTED_PROXIES — the client address behind a load balancer)', config('security.trusted_proxies') !== null],
            ['log level is not debug (LOG_LEVEL)', strtolower((string) config('logging.channels.single.level')) !== 'debug'],
            ['no development secret is set (LOCAL_FIXTURE_PASSWORD, OTP_DEV_CODE, ADMIN_BOOTSTRAP_PASSWORD)', (string) config('auth_security.fixture_password') === '' && (string) config('otp.development.code') === '' && (string) config('auth_security.bootstrap_password') === ''],
            ['legal documents are approved versions (no draft-*)', collect((array) config('legal.documents'))->every(fn ($d): bool => ! str_starts_with(strtolower((string) ($d['version'] ?? '')), 'draft'))],
        ];
        $failed = array_values(array_filter($checks, fn (array $c): bool => ! $c[1]));
        if ($this->option('json')) {
            $this->line((string) json_encode(['environment' => $env, 'judged_as_production' => $strict, 'passed' => count($checks) - count($failed), 'total' => count($checks), 'failed' => array_column($failed, 0)]));
        } else {
            $this->line("Security check — environment {$env}".($strict ? ' (judged as production)' : ' (local: shows what production must change; does not fail)'));
            foreach ($checks as [$name, $ok]) {
                $this->line(($ok ? '  PASS  ' : '  FAIL  ').$name);
            }
            $this->line(sprintf('%d / %d passed', count($checks) - count($failed), count($checks)));
        }

        return $strict && $failed !== [] ? self::FAILURE : self::SUCCESS;
    }

    private function appendOnlyTriggers(): bool
    {
        try {
            return (int) DB::selectOne("select count(*) as n from pg_trigger where not tgisinternal and tgname in ('audit_events_append_only', 'security_events_append_only')")->n === 2;
        } catch (\Throwable) {
            return false;
        }
    }

    private function auditPurgeOff(): bool
    {
        try {
            return (string) (DB::selectOne("select coalesce(current_setting('fotg.audit_purge', true), '') as v")->v ?? '') !== 'on';
        } catch (\Throwable) {
            return false;
        }
    }
}
