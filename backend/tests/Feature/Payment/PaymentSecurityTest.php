<?php

namespace Tests\Feature\Payment;

use App\Exceptions\ProviderException;
use App\Models\PaymentAttempt;
use App\Models\PaymentAttemptEvent;
use App\Models\PaymentWebhookEvent;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\Providers\FakePaymentProvider;
use App\Services\Payment\Providers\RazorpayPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\Support\BuildsPayments;
use Tests\TestCase;

/**
 * Payment security (Module 30): where the provider's secrets are and are not, what is logged, what no endpoint will
 * ever accept, and that the fake provider cannot stand in for a real one.
 */
class PaymentSecurityTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    private const SECRET = 'unit-test-key-secret-XYZ';

    private const WEBHOOK_SECRET = 'unit-test-webhook-secret-XYZ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_no_answer_of_the_payment_api_carries_a_provider_secret_or_a_signature(): void
    {
        config(['services.razorpay.key_id' => 'rzp_test_VisibleKeyId00001', 'services.razorpay.key_secret' => self::SECRET, 'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET]);
        [, $headers, $checkout, $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id']);
        $proof = $this->proofOf($payment['id'], $paid);

        $answers = [
            $this->showCheckout($headers, $checkout['id'])->assertOk()->getContent(),
            json_encode($payment),
            $this->completePayment($headers, $payment['id'], [...$proof, 'provider_signature' => str_repeat('ab', 32)])->assertStatus(422)->getContent(),
            $this->completePayment($headers, $payment['id'], $proof)->assertOk()->getContent(),
            $this->showPayment($headers, $payment['id'])->assertOk()->getContent(),
            $this->providerSends('payment.captured', $paid)->assertOk()->getContent(),
            $this->getJson('/api/v1/config')->assertOk()->getContent(),
        ];
        foreach ($answers as $answer) {
            foreach ([self::SECRET, self::WEBHOOK_SECRET, 'fake-provider-signing-key', 'fake-provider-webhook-key', $proof['provider_signature'], 'key_secret', 'webhook_secret'] as $never) {
                $this->assertStringNotContainsString($never, $answer);
            }
        }
        // And nothing of them is stored with a payment.
        $stored = json_encode([PaymentAttempt::query()->get()->map->getAttributes(), PaymentAttemptEvent::query()->get()->map->getAttributes(), PaymentWebhookEvent::query()->get()->map->getAttributes()]);
        foreach ([self::SECRET, self::WEBHOOK_SECRET, $proof['provider_signature'], 'fake-provider-signing-key'] as $never) {
            $this->assertStringNotContainsString($never, $stored);
        }
    }

    public function test_only_the_providers_public_key_id_can_reach_a_client(): void
    {
        config(['services.payment.driver' => 'razorpay', 'services.razorpay.key_id' => 'rzp_test_VisibleKeyId00001', 'services.razorpay.key_secret' => self::SECRET, 'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET]);
        $provider = $this->app->make(PaymentProviderManager::class)->provider();

        $this->assertInstanceOf(RazorpayPaymentProvider::class, $provider);
        $this->assertSame('rzp_test_VisibleKeyId00001', $provider->clientKey());
        // The interface has one value for clients — and no method that returns a secret.
        $public = array_map(fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass($provider))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($public as $method) {
            $this->assertDoesNotMatchRegularExpression('/secret/i', $method);
        }
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->app->make(PaymentProviderManager::class)->report()));
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->app->make(PaymentProviderManager::class)->summary()));
    }

    public function test_the_fake_provider_never_stands_in_for_a_real_one(): void
    {
        $manager = $this->app->make(PaymentProviderManager::class);
        $usable = function () use ($manager): ?string {
            try {
                return $manager->provider()->name();
            } catch (ProviderException) {
                return null;
            }
        };

        $this->assertSame('fake', $usable());   // this test environment
        // Razorpay named and not configured: nothing — never the fake provider instead.
        config(['services.payment.driver' => 'razorpay']);
        $this->assertNull($usable());
        $this->assertSame('UNAVAILABLE', $manager->summary()['status']);
        // The fake provider named where fake providers are not allowed: nothing.
        config(['services.payment.driver' => 'fake', 'payments.providers.allow_fake' => false]);
        $this->assertNull($usable());
        $this->assertNull($manager->named('fake'));
        // No provider named: nothing. An unknown name: nothing.
        config(['services.payment.driver' => null, 'payments.providers.allow_fake' => true]);
        $this->assertNull($usable());
        config(['services.payment.driver' => 'stripe']);
        $this->assertNull($usable());
        // A live key is refused: this build takes test-mode payments only.
        config(['services.payment.driver' => 'razorpay', 'services.razorpay.key_id' => 'rzp_live_ShouldBeRefused01', 'services.razorpay.key_secret' => self::SECRET, 'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET]);
        $this->assertNull($usable());
        $this->assertFalse((bool) config('payments.providers.allow_live'));
        config(['services.razorpay.key_id' => 'rzp_test_VisibleKeyId00001']);
        $this->assertSame('razorpay', $usable());
    }

    public function test_in_production_the_fake_provider_is_refused_whatever_the_environment_file_says(): void
    {
        // The configuration file decides from APP_ENV: read it as production would.
        $read = function (array $env): bool {
            $before = [];
            foreach ($env as $key => $value) {
                $before[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
            try {
                return (bool) (require base_path('config/payments.php'))['providers']['allow_fake'];
            } finally {
                foreach ($before as $key => [$env, $e, $s]) {
                    $env === false ? putenv($key) : putenv("{$key}={$env}");
                    if ($e === null) {
                        unset($_ENV[$key]);
                    } else {
                        $_ENV[$key] = $e;
                    }
                    if ($s === null) {
                        unset($_SERVER[$key]);
                    } else {
                        $_SERVER[$key] = $s;
                    }
                }
            }
        };

        $this->assertFalse($read(['APP_ENV' => 'production', 'PAYMENT_ALLOW_FAKE_PROVIDER' => 'true']));
        $this->assertFalse($read(['APP_ENV' => 'production', 'PAYMENT_ALLOW_FAKE_PROVIDER' => '']));
        $this->assertTrue($read(['APP_ENV' => 'local', 'PAYMENT_ALLOW_FAKE_PROVIDER' => '']));
        $this->assertFalse($read(['APP_ENV' => 'local', 'PAYMENT_ALLOW_FAKE_PROVIDER' => 'false']));
        // Live payments are not something an environment variable can switch on.
        $this->assertFalse((bool) (require base_path('config/payments.php'))['providers']['allow_live']);
        $this->assertStringNotContainsString("env('PAYMENT_ALLOW_LIVE", (string) File::get(base_path('config/payments.php')));
    }

    public function test_payment_events_are_logged_with_ids_states_and_codes_only(): void
    {
        Log::spy();
        config(['services.razorpay.key_secret' => self::SECRET, 'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET]);
        [, $headers, , $payment] = $this->openPayment();
        $paid = $this->providerPays($payment['id'], 'captured', ['method' => 'CARD']);
        $proof = $this->proofOf($payment['id'], $paid);
        $this->completePayment($headers, $payment['id'], [...$proof, 'provider_signature' => str_repeat('cd', 32)])->assertStatus(422);
        $this->completePayment($headers, $payment['id'], $proof)->assertOk();
        [$body, $webhookHeaders] = $this->fakeProvider()->webhook('payment.captured', $paid);
        $this->sendWebhook($body, [...$webhookHeaders, FakePaymentProvider::SIGNATURE_HEADER => str_repeat('0', 64)])->assertStatus(401);
        $this->sendWebhook($body, $webhookHeaders)->assertOk();

        $lines = [];
        foreach (['info', 'warning', 'log'] as $method) {
            Log::shouldHaveReceived($method)->withArgs(function (...$args) use (&$lines): bool {
                $lines[] = json_encode($args);

                return true;
            });
        }
        $log = implode("\n", $lines);
        foreach (['payment.attempt_created', 'payment.provider_ready', 'payment.signature_invalid', 'payment.verified', 'payment.webhook_rejected', 'payment.webhook_received'] as $expected) {
            $this->assertStringContainsString($expected, $log);
        }
        $this->assertStringContainsString($payment['id'], $log);
        $never = [
            self::SECRET, self::WEBHOOK_SECRET, 'fake-provider-signing-key', 'fake-provider-webhook-key',
            $proof['provider_signature'], $webhookHeaders[FakePaymentProvider::SIGNATURE_HEADER], str_repeat('cd', 32),
            '+919876543210', $headers['Authorization'], substr($headers['Authorization'], 7), '1007', 'Visa', $body,
        ];
        foreach ($never as $value) {
            $this->assertStringNotContainsString($value, $log);
        }
    }

    public function test_no_table_of_the_payment_domain_has_a_column_for_a_credential_or_a_raw_payload(): void
    {
        foreach (['payment_attempts', 'payment_attempt_events', 'payment_webhook_events'] as $table) {
            // Two columns say WHEN a proof was verified and how often one failed — neither holds a proof.
            foreach (array_diff(Schema::getColumnListing($table), ['signature_verified_at', 'signature_failures']) as $column) {
                $this->assertDoesNotMatchRegularExpression('/card_number|pan\b|cvv|cvc|expiry|upi_pin|\bpin\b|password|otp|secret|signature|raw|payload\b|token/', $column, "{$table}.{$column}");
            }
        }
        // Money is integer minor units with a currency: no decimal or float column anywhere in the domain.
        $types = Schema::getColumns('payment_attempts');
        foreach ($types as $column) {
            $this->assertNotContains($column['type_name'], ['numeric', 'decimal', 'float4', 'float8', 'money'], $column['name']);
        }
        $this->assertSame('int8', collect($types)->firstWhere('name', 'amount_minor')['type_name']);
        $this->assertSame('bpchar', collect($types)->firstWhere('name', 'currency')['type_name']);
    }

    public function test_no_endpoint_of_the_api_accepts_card_upi_or_bank_credentials(): void
    {
        // Every validation rule set of the API controllers is searched for a field that would take a credential.
        $accepted = [];
        foreach ((new Finder)->files()->in(app_path('Http/Controllers'))->name('*.php') as $file) {
            $source = $file->getContents();
            // A credential field name used as a validated key — unless the rule is "prohibited" (refused outright).
            if (preg_match_all("/'((?:[a-z_]+\.)?(?:card_number|cvv|cvc|upi_pin|bank_password|netbanking_password))'\s*=>\s*(\[[^\]]*\])/", $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (! str_contains($match[2], 'prohibited')) {
                        $accepted[] = $file->getRelativePathname().': '.$match[1];
                    }
                }
            }
        }
        $this->assertSame([], $accepted);

        // And the payment endpoints refuse them outright, wherever they are put.
        [, $headers, $checkout, $payment] = $this->openPayment();
        foreach ([['card_number' => '4111111111111111'], ['cvv' => '123'], ['cvc' => '123'], ['upi_pin' => '1234'], ['bank_password' => 'x'], ['otp' => '123456'], ['pan' => '4111111111111111'], ['vpa' => 'a@b'], ['cash_at_pickup' => true]] as $credential) {
            $this->completePayment($headers, $payment['id'], ['outcome' => 'cancelled', ...$credential])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->assertSame('PROVIDER_READY', $this->attempt($payment['id'])->status->value);
        $this->assertSame(0, PaymentAttemptEvent::query()->where('type', 'client_callback')->count());
        $this->assertStringNotContainsString('4111111111111111', json_encode([PaymentAttempt::query()->get()->map->getAttributes(), PaymentAttemptEvent::query()->get()->map->getAttributes()]));
        $this->startPayment($headers, $checkout, ['payment_method' => ['type' => 'UPI', 'upi_pin' => '1234']])->assertStatus(422);
    }

    public function test_the_application_code_holds_no_provider_secret_and_reads_none_outside_the_provider_layer(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in([app_path(), base_path('routes'), base_path('database')])->name('*.php') as $file) {
            $source = $file->getContents();
            $path = str_replace('\\', '/', $file->getRelativePathname());
            // A literal that looks like a provider key or secret.
            if (preg_match('/rzp_(live|test)_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}/', $source) === 1) {
                $offenders[] = "{$path}: a provider key literal";
            }
            // The secrets are read by the provider manager only, and used by the Razorpay adapter only.
            if (preg_match("/services\.razorpay\.(key_secret|webhook_secret)|config\('services\.razorpay'\)/", $source) === 1 && ! str_ends_with($path, 'Services/Payment/PaymentProviderManager.php')) {
                $offenders[] = "{$path}: reads the provider's secrets";
            }
            if (preg_match("/\['(key_secret|webhook_secret)'\]/", $source) === 1 && ! str_ends_with($path, 'Services/Payment/Providers/RazorpayPaymentProvider.php')) {
                $offenders[] = "{$path}: uses a provider secret";
            }
        }
        $this->assertSame([], $offenders);
        // .env.example names the keys and leaves them empty.
        $example = (string) File::get(base_path('.env.example'));
        foreach (['RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET'] as $key) {
            $this->assertMatchesRegularExpression('/^'.$key.'=$/m', $example);
        }
        $this->assertMatchesRegularExpression('/^PAYMENT_DRIVER=$/m', $example);
    }

    public function test_reading_a_payment_and_the_webhook_endpoint_have_their_own_limits(): void
    {
        config(['rate_limits.payment_read' => 3, 'rate_limits.payment_webhook' => 2]);
        [, $headers, , $payment] = $this->openPayment();

        foreach (range(1, 3) as $_) {
            $this->showPayment($headers, $payment['id'])->assertOk();
        }
        $this->showPayment($headers, $payment['id'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        // The webhook limit counts the sending address, not a customer — and is not the general API limit.
        $paid = $this->providerPays($payment['id']);
        $this->providerSends('payment.captured', $paid, 'evt_limit_0001')->assertOk();
        $this->providerSends('payment.captured', $paid, 'evt_limit_0001')->assertOk();
        $this->providerSends('payment.captured', $paid, 'evt_limit_0001')->assertStatus(429);
        $this->assertSame('VERIFIED', $this->attempt($payment['id'])->status->value);
    }
}
