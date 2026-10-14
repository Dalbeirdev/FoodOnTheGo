<?php

namespace Tests\Feature\Security;

use App\Enums\SecurityEventType;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Models\IdempotencyKey;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderPickupCredential;
use App\Models\PaymentWebhookEvent;
use App\Services\Audit\AuditRecorder;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Menu\MenuCatalog;
use App\Services\Order\PickupCredentials;
use App\Support\ImageSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 39 — data protection: stored replies of idempotent requests are encrypted at rest; the audit and security
 * trails cannot be rewritten; a push binding ends with its session; restaurant staff see a customer's contact only
 * while it is needed; a restricted account cannot pay; erasure anonymises and keeps the financial record; uploads lose
 * their metadata and anything appended; keyed pickup code hashes; a replayed webhook body is a duplicate.
 */
class DataProtectionTest extends TestCase
{
    use BuildsSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_stored_replies_of_idempotent_requests_are_encrypted_at_rest(): void
    {
        // The reply that carried a pickup secret (a reissued credential) and the reply of a payment start.
        [, $headers, $order] = $this->readyOrder();
        $reissued = $this->postJson('/api/v1/customer/orders/'.$order['order_number'].'/pickup-credential/reissue', [], [...$this->using($headers), 'Idempotency-Key' => 'm39-reissue-000000001'])->assertSuccessful()->json();
        $secrets = array_filter([data_get($reissued, 'pickup_credential.code'), data_get($reissued, 'pickup_credential.qr_payload')]);
        $this->assertNotEmpty($secrets);
        [, $payer, $checkout] = $this->readyCheckout(phone: '+919811122233');
        $this->startPayment($payer, $checkout, [], ['Idempotency-Key' => 'm39-payment-000000001'])->assertCreated();

        $rows = DB::table('idempotency_keys')->whereNotNull('response_body')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        foreach ($rows as $row) {
            $raw = (string) $row->response_body;
            $this->assertNull(json_decode($raw, true), 'the column holds ciphertext, not JSON');
            foreach ([...$secrets, $order['order_number'], '9876543210', '9811122233', '"status"'] as $plain) {
                $this->assertStringNotContainsString($plain, $raw);
            }
            $this->assertIsArray(IdempotencyKey::query()->findOrFail($row->id)->response_body, 'the application still reads it');
        }
    }

    public function test_the_audit_and_security_trails_cannot_be_rewritten_or_deleted(): void
    {
        $customer = Customer::factory()->create();
        app(SecurityEventRecorder::class)->record(SecurityEventType::LoginFailed, null, ['method' => 'test'], 'someone@foodonthego.example');
        app(SecurityEventRecorder::class)->record(SecurityEventType::LoginFailed, null, ['method' => 'test'], 'other@foodonthego.example');
        app(AuditRecorder::class)->record('m39.test', $customer, null);
        $refused = function (callable $write): bool {
            try {
                DB::transaction($write);   // a savepoint: the refused statement does not abort the test's transaction
            } catch (QueryException $e) {
                return str_contains($e->getMessage(), 'append-only');
            }

            return false;
        };

        foreach (['audit_events', 'security_events'] as $table) {
            $this->assertTrue($refused(fn () => DB::table($table)->update(['id' => DB::raw('id')])), "{$table}: UPDATE");
            $this->assertTrue($refused(fn () => DB::table($table)->delete()), "{$table}: DELETE");
            $this->assertGreaterThan(0, DB::table($table)->count());
        }

        // Retention removes rows only through the deliberate, transaction-local switch.
        $before = DB::table('security_events')->count();
        DB::transaction(function (): void {
            DB::statement("set local fotg.audit_purge = 'on'");
            DB::table('security_events')->where('id', DB::table('security_events')->min('id'))->delete();
        });
        $this->assertSame($before - 1, DB::table('security_events')->count());
        // (`set local` ends with its transaction; inside this test's own wrapping transaction it cannot be shown to end.)
    }

    public function test_a_push_binding_ends_with_the_session_that_registered_it(): void
    {
        [$customer, $headers] = $this->signedIn('+919876543210');
        $register = fn (array $h, string $installation) => $this->withHeaders($this->using($h))->putJson('/api/v1/customer/devices/current', ['installation_id' => $installation, 'platform' => 'ANDROID', 'push_provider' => 'FAKE', 'push_token' => 'fake-token-'.$installation])->assertSuccessful()->json('device.id');
        $phone = $register($headers, 'install-phone-0001');
        $second = $this->sessionOf($customer, 'tablet');
        $tablet = $register($second, 'install-tablet-001');
        $active = fn (): array => CustomerDevice::query()->where('customer_id', $customer->getKey())->active()->pluck('public_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$phone, $tablet], $active());

        // Signing out on the phone unbinds the phone only.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], $this->using($headers))->assertSuccessful();
        $this->assertSame([$tablet], $active());
        $this->assertFalse(CustomerDevice::query()->where('public_id', $phone)->firstOrFail()->isActive());

        // An expired session unbinds too, without anyone signing out.
        DB::table('personal_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], $active());
    }

    public function test_restaurant_staff_see_a_customers_contact_only_while_the_order_needs_it(): void
    {
        [, , , , $finished] = $this->paidOrder('+919876543210');
        [, , , , $open] = $this->paidOrder('+919811122233');
        $this->orderStaff();
        $this->step($finished['order_number'], 'reject', ['reason' => 'ITEM_UNAVAILABLE'])->assertOk();

        $this->restaurantOrder($finished['order_number'])->assertOk()->assertJsonPath('order.customer.contact_masked', false)->assertJsonPath('order.customer.phone', '+919876543210');

        $this->travel((int) config('security.restaurant_contact_hours') + 1)->hours();
        $masked = $this->restaurantOrder($finished['order_number'])->assertOk()->json('order.customer');
        $this->assertTrue($masked['contact_masked']);
        $this->assertStringNotContainsString('9876543210', (string) json_encode($masked));
        $this->assertStringEndsWith('3210', (string) $masked['phone']);
        $list = $this->restaurantOrders('status=REJECTED')->assertOk();
        $this->assertStringNotContainsString('9876543210', $list->getContent());
        // An order still in progress keeps the full contact, however old.
        $this->restaurantOrder($open['order_number'])->assertOk()->assertJsonPath('order.customer.contact_masked', false)->assertJsonPath('order.customer.phone', '+919811122233');
    }

    public function test_a_restricted_account_can_neither_open_a_checkout_nor_start_a_payment(): void
    {
        [$customer, $headers, $checkout] = $this->readyCheckout();
        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true], $this->using($headers))->assertOk()->assertJsonPath('deletion.account_status', 'RESTRICTED');

        $this->startPayment($headers, $checkout)->assertForbidden()->assertJsonPath('error.code', 'account_restricted');
        $this->openCheckout($headers)->assertForbidden()->assertJsonPath('error.code', 'account_restricted');
        $this->assertSame(0, DB::table('payment_attempts')->count());
        // The account itself stays readable (the customer can still see their data while the request is handled).
        $this->getJson('/api/v1/customer/profile', $this->using($headers))->assertOk()->assertJsonPath('status', 'RESTRICTED');
    }

    public function test_erasure_waits_for_open_business_then_anonymises_and_keeps_the_financial_record(): void
    {
        [$customer, $headers, $order] = $this->readyOrder('+919876543210');
        $erase = function (array $options = []) use ($customer): array {
            $code = Artisan::call('privacy:erase-customer', ['customer' => (string) $customer->public_id, ...$options]);

            return [$code, json_decode(trim(Artisan::output()), true)];
        };

        // Without a request, and while an order is open: refused, nothing changes.
        [$code, $answer] = $erase(['--execute' => true, '--reference' => 'DSR-1']);
        $this->assertSame(1, $code);
        $this->assertContains('no_deletion_request', $answer['blockers']);
        $this->assertContains('open_orders:1', $answer['blockers']);

        $credential = $this->credentialOf($headers, $order['order_number']);
        $this->verifyQr($credential['qr_payload'])->assertOk()->assertJsonPath('order.status', 'COMPLETED');
        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true], $this->using($headers))->assertOk();

        // A dry run says what would change and changes nothing.
        [$code, $dry] = $erase();
        $this->assertSame(0, $code);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(1, $dry['would_change']['orders_contact_masked']);
        $this->assertSame('+919876543210', $customer->fresh()->phone_e164);
        // Executing needs the reference of the request it answers.
        $this->assertSame(1, $erase(['--execute' => true])[0]);

        [$code, $done] = $erase(['--execute' => true, '--reference' => 'DSR-2026-0001']);
        $this->assertSame(0, $code);
        $this->assertTrue($done['erased']);
        $fresh = Customer::query()->findOrFail($customer->getKey());
        $this->assertSame('DEACTIVATED', $fresh->status->value);
        $this->assertNull($fresh->email);
        $this->assertStringStartsWith('+999', (string) $fresh->phone_e164);
        $this->assertNotSame('Rahul', $fresh->name);
        // The order (a financial record) stays, with the contact masked.
        $row = Order::query()->where('order_number', $order['order_number'])->firstOrFail();
        $this->assertSame('COMPLETED', $row->status->value);
        $this->assertSame((int) $order['total']['amount'], $row->total()->toArray()['amount']);
        $this->assertStringNotContainsString('9876543210', (string) json_encode($row->snapshot));
        // Sessions are gone; the action is audited with its reference.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $this->using($headers))->assertUnauthorized();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.erased')->count());
        $this->assertStringContainsString('DSR-2026-0001', (string) DB::table('audit_events')->where('action', 'customer.erased')->value('reason'));
        // Erasing twice is refused with its reason: nothing is left to erase.
        [$code, $again] = $erase(['--execute' => true, '--reference' => 'DSR-2']);
        $this->assertSame(1, $code);
        $this->assertSame(['already_erased'], $again['blockers']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.erased')->count());
    }

    public function test_uploaded_images_lose_their_metadata_and_anything_appended_to_them(): void
    {
        $image = imagecreatetruecolor(160, 160);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 50, 50));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        // A JPEG with an EXIF segment (where a phone writes the GPS position) right after the start marker.
        $exif = "Exif\0\0GPSLatitude=28.5355;GPSLongitude=77.3910;Owner=Priya";
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        // A PNG with a script appended after its end (a polyglot).
        $polyglot = $png.'<?php system($_GET["c"]); ?>';

        foreach ([['image/jpeg', $withExif, 'GPSLatitude'], ['image/png', $polyglot, '<?php']] as [$mime, $bytes, $marker]) {
            $path = tempnam(sys_get_temp_dir(), 'm39');
            file_put_contents($path, $bytes);
            $clean = ImageSanitizer::reencode($path, $mime);
            @unlink($path);
            $this->assertStringContainsString($marker, $bytes);
            $this->assertStringNotContainsString($marker, $clean, "{$mime}: the stored file is a re-encoded image");
            $this->assertNotFalse(@imagecreatefromstring($clean), "{$mime}: still a readable image");
        }

        // A file that is not an image is refused, whatever it calls itself.
        $fake = tempnam(sys_get_temp_dir(), 'm39');
        file_put_contents($fake, '<?php echo "hello"; ?>');
        try {
            ImageSanitizer::reencode($fake, 'image/png');
            $this->fail('a script must not pass as an image');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($fake);
        }

        // Through the API: the avatar a customer uploads is stored re-encoded.
        Storage::fake('public');
        [$customer, $headers] = $this->signedIn('+919876543210');
        $upload = $this->post('/api/v1/customer/profile/avatar', ['image' => UploadedFile::fake()->createWithContent('me.jpg', $withExif)], [...$this->using($headers), 'Accept' => 'application/json']);
        $this->assertSame(200, $upload->status(), $upload->getContent());
        $stored = (string) Storage::disk('public')->get((string) $customer->fresh()->avatar_path);
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('GPSLatitude', $stored);
    }

    public function test_manual_pickup_codes_are_stored_as_keyed_hashes_and_the_unkeyed_form_is_only_honoured_for_old_credentials(): void
    {
        [, $headers, $order] = $this->readyOrder();
        $code = $this->credentialOf($headers, $order['order_number'])['code'];
        $model = Order::query()->where('order_number', $order['order_number'])->firstOrFail();
        $credential = OrderPickupCredential::query()->where('order_id', $model->getKey())->whereNull('revoked_at')->latest('id')->firstOrFail();
        $normalised = PickupCredentials::normalizeCode($code);

        $this->assertSame(PickupCredentials::codeHash($model, $normalised), $credential->display_code_hash);
        $this->assertNotSame(hash('sha256', $model->public_id.':'.$normalised), $credential->display_code_hash, 'not computable without the application key');

        // A credential issued after the switch with an unkeyed hash (as a stolen database dump would allow to forge) is refused.
        $credential->forceFill(['display_code_hash' => PickupCredentials::legacyCodeHash($model, $normalised), 'issued_at' => '2026-10-15 00:00:00'])->save();
        $this->verifyCode($order['order_number'], $code)->assertStatus(422);
        $this->assertSame('READY_FOR_PICKUP', $model->fresh()->status->value);
        // One issued before the switch (still valid when the module was deployed) is honoured once more.
        $credential->forceFill(['issued_at' => '2026-10-07 11:00:00'])->save();
        $this->verifyCode($order['order_number'], $code)->assertOk();
    }

    public function test_a_replayed_webhook_body_under_a_new_event_id_is_a_duplicate(): void
    {
        [, $headers, $checkout] = $this->readyCheckout();
        $payment = $this->startPayment($headers, $checkout)->assertCreated()->json('payment');
        $paid = $this->providerPays($payment['id']);
        [$body, $signed] = $this->fakeProvider()->webhook('payment.captured', $paid, 'fakeevt_first_delivery_01');

        $this->sendWebhook($body, $signed)->assertSuccessful();
        $replay = $signed;
        foreach (array_keys($replay) as $name) {
            if (stripos($name, 'event') !== false) {
                $replay[$name] = 'fakeevt_attacker_chosen_02';
            }
        }
        $this->assertNotSame($signed, $replay, 'the event-id header was replaced');
        $this->sendWebhook($body, $replay)->assertSuccessful();

        $this->assertSame(1, PaymentWebhookEvent::query()->count(), 'one signed body is one event');
        $this->assertSame(2, (int) PaymentWebhookEvent::query()->sole()->deliveries);
    }

    public function test_cached_menu_documents_are_kept_per_host(): void
    {
        $menu = Menu::query()->firstOrFail();
        $catalog = app(MenuCatalog::class);
        URL::forceRootUrl('https://api.foodonthego.example');
        $real = $catalog->key($menu, 'public');
        URL::forceRootUrl('https://attacker.example');
        $poisoned = $catalog->key($menu, 'public');
        URL::forceRootUrl(null);
        $this->assertNotSame($real, $poisoned, 'a request with another Host header never fills the cache other hosts read');
    }
}
