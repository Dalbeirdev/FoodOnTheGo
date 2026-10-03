<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerFavoriteLocation;
use App\Services\Customer\CustomerFavoriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCustomers;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Sensitive account actions (Module 25): re-authentication by a code to the account's phone, the deletion
 * request (recorded, RESTRICTED, nothing deleted, sessions kept), and the verified phone change (code to the
 * new number, uniqueness, identity update, other sessions revoked, security events). The development code is
 * 123456 (OTP_DEV_CODE in phpunit.xml) — nothing is sent.
 */
class AccountSecurityTest extends TestCase
{
    use BuildsCustomers, BuildsRestaurants, RefreshDatabase;

    private Customer $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->rahul = $this->customer($this->market, ['name' => 'Rahul Sharma', 'phone_e164' => '+919876543210']);
    }

    public function test_a_fresh_session_counts_as_recent_an_old_one_must_verify_a_code_first(): void
    {
        $fresh = $this->sessionOf($this->rahul);
        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true], $this->using($fresh))->assertOk();

        $other = $this->customer($this->market, ['phone_e164' => '+919876500002']);
        $old = $this->sessionOf($other);
        DB::table('personal_access_tokens')->update(['created_at' => now()->subHours(2)]);
        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true], $this->using($old))->assertForbidden()->assertJsonPath('error.code', 'reauthentication_required');
        $this->assertNull($other->fresh()->deletion_requested_at);

        $challenge = $this->postJson('/api/v1/customer/account/reauth', [], $this->using($old))->assertOk()->assertJsonStructure(['challenge_id', 'phone_masked', 'expires_at', 'attempts_allowed', 'delivery', 'purpose'])->json();
        $this->assertSame('reauth', $challenge['purpose']);
        $this->assertStringNotContainsString('123456', json_encode($challenge));
        $this->postJson('/api/v1/customer/account/reauth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '000000'], $this->using($old))->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid');
        $this->postJson('/api/v1/customer/account/reauth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], $this->using($old))->assertOk()->assertJsonPath('reauthenticated', true);
        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true, 'reason' => 'Moving abroad'], $this->using($old))->assertOk()->assertJsonPath('deletion.status', 'requested');
        $this->assertSame('REAUTHENTICATED', DB::table('security_events')->where('event', 'REAUTHENTICATED')->value('event'));
    }

    public function test_the_deletion_request_restricts_the_account_keeps_data_and_sessions_and_is_idempotent(): void
    {
        $session = $this->sessionOf($this->rahul);
        $location = $this->location($this->organization('Riverside'), 'Burger Hub', ['slug' => 'burger-hub']);
        app(CustomerFavoriteService::class)->add($this->rahul, $location);

        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => false], $this->using($session))->assertUnprocessable();
        $this->getJson('/api/v1/customer/account/deletion-request', $this->using($session))->assertStatus(405);
        $r = $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true, 'reason' => 'No longer travelling'], $this->using($session))->assertOk()->json();
        $this->assertSame(['requested', 'RESTRICTED', 'RESTRICTED'], [$r['deletion']['status'], $r['deletion']['account_status'], $r['data']['status']]);
        $this->assertNotNull($r['data']['deletion_requested_at']);

        $fresh = $this->rahul->fresh();
        $this->assertSame('RESTRICTED', $fresh->status->value);
        $this->assertSame('No longer travelling', $fresh->deletion_reason);
        $this->assertDatabaseCount('customers', 1); // nothing is deleted
        $this->assertSame(1, CustomerFavoriteLocation::query()->where('customer_id', $this->rahul->getKey())->count(), 'preference data is kept until the privacy process decides');
        $this->getJson('/api/v1/customer/profile', $this->using($session))->assertOk()->assertJsonPath('status', 'RESTRICTED'); // the session stays usable
        $first = $fresh->deletion_requested_at;
        $this->travel(10)->minutes(); // still a fresh session
        $this->postJson('/api/v1/customer/account/deletion-request', ['confirm' => true], $this->using($session))->assertOk();
        $this->assertSame($first->toIso8601String(), $this->rahul->fresh()->deletion_requested_at->toIso8601String(), 'a second request changes nothing');
        $this->assertSame(1, DB::table('security_events')->where('event', 'ACCOUNT_DELETION_REQUESTED')->count());
        $this->assertSame(1, DB::table('security_events')->where('event', 'ACCOUNT_STATUS_CHANGED')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.deletion_requested')->count());
        $this->assertStringNotContainsString('No longer travelling', json_encode(DB::table('audit_events')->get()), 'the reason is not copied into the audit trail');
    }

    public function test_the_phone_changes_only_through_a_code_sent_to_the_new_number(): void
    {
        $session = $this->sessionOf($this->rahul, 'phone');
        $second = $this->sessionOf($this->rahul, 'tablet');
        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '98765 43210'], $this->using($session))->assertUnprocessable()->assertJsonPath('error.details.fields.phone.0', 'This is already the number of your account.');
        $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '12345'], $this->using($session))->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['phone']]]]);
        $this->customer($this->market, ['phone_e164' => '+919876500009']);
        $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '9876500009'], $this->using($session))->assertStatus(409)->assertJsonPath('error.code', 'phone_in_use');

        $challenge = $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '9876501234'], $this->using($session))->assertOk()->json();
        $this->assertSame('phone_change', $challenge['purpose']);
        $this->assertMatchesRegularExpression('/^\+91.*1234$/', $challenge['phone_masked']);
        $this->assertStringNotContainsString('9876501234', str_replace('1234', '', $challenge['phone_masked']));
        $this->assertSame('PENDING', DB::table('customer_phone_changes')->where('public_id', $challenge['change_id'])->value('status'));
        $this->assertSame('customer_phone_change', DB::table('otp_challenges')->where('public_id', $challenge['challenge_id'])->value('purpose'));
        $this->assertSame('+919876543210', $this->rahul->fresh()->phone_e164, 'nothing changes before the code is verified');

        $this->postJson('/api/v1/customer/phone-change/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '999999'], $this->using($session))->assertUnprocessable()->assertJsonPath('error.code', 'otp_invalid');
        $p = $this->postJson('/api/v1/customer/phone-change/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], $this->using($session))->assertOk()->json();
        $this->assertSame('+919876501234', $p['phone']);
        $this->assertTrue($p['phone_verified']);
        $fresh = $this->rahul->fresh();
        $this->assertSame('+919876501234', $fresh->phone_e164);
        $this->assertSame('COMPLETED', DB::table('customer_phone_changes')->where('public_id', $challenge['change_id'])->value('status'));
        // this session stays, the other one is gone
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->getJson('/api/v1/customer/profile', $this->using($session))->assertOk();
        $this->getJson('/api/v1/customer/profile', $this->using($second))->assertUnauthorized();
        $events = DB::table('security_events')->whereIn('event', ['PHONE_CHANGE_REQUESTED', 'PHONE_CHANGED'])->orderBy('id')->pluck('event')->all();
        $this->assertSame(['PHONE_CHANGE_REQUESTED', 'PHONE_CHANGED'], $events);
        $metadata = json_decode(DB::table('security_events')->where('event', 'PHONE_CHANGED')->value('metadata'), true);
        $this->assertSame(1, $metadata['sessions_revoked']);
        $this->assertStringNotContainsString('+919876501234', json_encode($metadata), 'only masked numbers in security events');
        $this->assertStringNotContainsString('+919876501234', json_encode(DB::table('audit_events')->where('action', 'customer.phone_changed')->get()));
        // the used code cannot be replayed
        $this->postJson('/api/v1/customer/phone-change/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], $this->using($session))->assertUnprocessable()->assertJsonPath('error.code', 'phone_change_invalid');
    }

    public function test_a_phone_change_needs_recent_authentication_and_a_stale_or_foreign_code_is_refused(): void
    {
        $session = $this->sessionOf($this->rahul);
        DB::table('personal_access_tokens')->update(['created_at' => now()->subHours(2)]);
        $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '9876501234'], $this->using($session))->assertForbidden()->assertJsonPath('error.code', 'reauthentication_required');

        DB::table('personal_access_tokens')->update(['created_at' => now()]);
        $challenge = $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '9876501234'], $this->using($session))->assertOk()->json();
        // another customer cannot complete my change with my challenge id
        $asha = $this->customer($this->market, ['phone_e164' => '+919876543212']);
        $this->postJson('/api/v1/customer/phone-change/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], $this->sessionOf($asha))->assertUnprocessable()->assertJsonPath('error.code', 'phone_change_invalid');
        $this->assertSame('+919876543212', $asha->fresh()->phone_e164);
        // expired
        DB::table('customer_phone_changes')->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/customer/phone-change/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], $this->using($session))->assertUnprocessable()->assertJsonPath('error.code', 'phone_change_invalid');
        $this->assertSame('EXPIRED', DB::table('customer_phone_changes')->value('status'));
        $this->assertSame('+919876543210', $this->rahul->fresh()->phone_e164);
        // a new request cancels nothing that matters and the profile route still refuses the phone
        $this->patchJson('/api/v1/customer/profile', ['phone_e164' => '+919876501234'], $this->using($session))->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/customer/account/reauth')->assertUnauthorized();
        $this->postJson('/api/v1/customer/phone-change/request', ['phone' => '9876501234'])->assertUnauthorized();
    }
}
