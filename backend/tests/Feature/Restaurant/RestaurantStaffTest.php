<?php

namespace Tests\Feature\Restaurant;

use App\Enums\MembershipStatus;
use App\Enums\Permission as P;
use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Models\AuditEvent;
use App\Models\RestaurantLocation;
use App\Models\RestaurantMembership;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Notifications\RestaurantStaffInvitationNotification;
use App\Services\Rbac\RoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Staff memberships of a restaurant organization: invitation, activation, role, location access, suspension,
 * revocation — and the rules no permission overrides.
 */
class RestaurantStaffTest extends TestCase
{
    use BuildsRestaurants, RefreshDatabase;

    private const PASSWORD = 'a-long-enough-password';

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    private RestaurantLocation $brew;

    private RestaurantUser $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestaurantWorld();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

        $this->organization = $this->organization('Riverside');
        $this->burger = $this->location($this->organization, 'Burger Hub');
        $this->brew = $this->location($this->organization, 'Brew & Bites');
        $this->owner = $this->member($this->organization, 'OWNER');
        $this->actingAsPrincipal($this->owner);
    }

    private function url(string $path = '', ?RestaurantOrganization $organization = null): string
    {
        return '/api/v1/restaurant/organizations/'.($organization ?? $this->organization)->public_id.'/staff'.$path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invite(array $overrides = []): TestResponse
    {
        return $this->postJson($this->url(), ['name' => 'Priya Nair', 'email' => 'Priya.Nair@Restaurant.example', 'role' => 'MANAGER', 'all_locations' => false, 'location_ids' => [$this->burger->public_id], ...$overrides]);
    }

    /**
     * The token of the newest invitation link sent to the user.
     */
    private function linkToken(RestaurantUser $user): string
    {
        $token = '';
        Notification::assertSentTo($user, RestaurantStaffInvitationNotification::class, function (RestaurantStaffInvitationNotification $notification) use (&$token): bool {
            $token = substr($notification->url(), strpos($notification->url(), '#') + 1);

            return true;
        });

        return $token;
    }

    private function accept(string $token, ?string $password = self::PASSWORD): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/restaurant/invitation/accept', ['token' => $token, ...($password === null ? [] : ['password' => $password, 'password_confirmation' => $password])]);
    }

    /**
     * @return list<string> "ROLE scope_type" of the user's assignments
     */
    private function assignments(RestaurantUser $user): array
    {
        return DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.principal_type', 'restaurant_user')->where('role_assignments.principal_id', $user->id)
            ->orderBy('role_assignments.scope_id')->get(['roles.code', 'role_assignments.scope_type', 'role_assignments.scope_id'])
            ->map(fn (object $a): string => $a->code.' '.$a->scope_type.' '.$a->scope_id)->all();
    }

    // ───────────────────────────── Invitation ─────────────────────────────

    public function test_an_invitation_creates_a_membership_without_any_access_and_sends_a_single_use_link(): void
    {
        $response = $this->invite()->assertCreated();

        $response->assertJsonPath('name', 'Priya Nair')->assertJsonPath('email', 'priya.nair@restaurant.example')
            ->assertJsonPath('role', ['code' => 'MANAGER', 'name' => 'Manager'])->assertJsonPath('status', 'INVITED')
            ->assertJsonPath('all_locations', false)->assertJsonPath('locations.*.name', ['Burger Hub'])
            ->assertJsonPath('accepted_at', null)->assertJsonPath('is_self', false)->assertJsonPath('version', 1);

        $user = RestaurantUser::query()->where('email', 'priya.nair@restaurant.example')->firstOrFail();
        $this->assertSame(StaffStatus::Invited, $user->status);
        $this->assertNull($user->password, 'nobody chooses a password for the invited person');
        $this->assertSame([], $this->assignments($user), 'an invitation grants nothing');

        $token = $this->linkToken($user);
        $this->assertSame(64, strlen($token));
        Notification::assertSentTo($user, RestaurantStaffInvitationNotification::class, fn (RestaurantStaffInvitationNotification $n): bool => str_starts_with($n->url(), rtrim((string) config('app.frontend_url'), '/').'/restaurant-dashboard/accept-invitation#'));

        $stored = DB::table('restaurant_staff_invitations')->sole();
        $this->assertSame(hash('sha256', $token), $stored->token_hash, 'only the hash of the link is stored');
        $this->assertNull($stored->used_at);
        $this->assertEquals(now()->addHours(72)->getTimestamp(), Carbon::parse($stored->expires_at)->getTimestamp());
        $this->assertStringNotContainsString($token, $response->getContent().json_encode(DB::table('restaurant_memberships')->get()).json_encode(AuditEvent::query()->get()));

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_staff.invited', $event->action);
        $this->assertSame($this->owner->public_id, $event->actor_public_id);
        $this->assertSame('restaurant_memberships', $event->target_type);
        $this->assertEquals(['member' => ['from' => null, 'to' => 'Priya Nair'], 'role' => ['from' => null, 'to' => 'MANAGER'], 'locations' => ['from' => null, 'to' => ['Burger Hub']]], $event->changes);

        // No password yet: no sign-in.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/restaurant/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertUnauthorized();
    }

    public function test_accepting_activates_the_account_and_the_membership_and_the_link_works_once(): void
    {
        $this->invite()->assertCreated();
        $user = RestaurantUser::query()->where('email', 'priya.nair@restaurant.example')->firstOrFail();
        $token = $this->linkToken($user);

        $this->accept($token, null)->assertUnprocessable()->assertJsonPath('error.details.fields.password.0', 'Choose a password to activate your account.');
        $this->accept($token, 'short')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(StaffStatus::Invited, $user->refresh()->status);

        $this->accept($token)->assertOk()->assertExactJson(['message' => 'The invitation is accepted. You can sign in now.', 'restaurant' => 'Riverside']);

        $user->refresh();
        $this->assertSame(StaffStatus::Active, $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $membership = $this->membershipOf($user, $this->organization);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertNotNull($membership->accepted_at);
        $this->assertSame(['MANAGER location '.$this->burger->public_id], $this->assignments($user), 'access is exactly what the membership says');
        $this->assertNotNull(DB::table('restaurant_staff_invitations')->value('used_at'));

        $this->accept($token)->assertUnprocessable()->assertJsonPath('error.code', 'invitation_invalid');

        $login = $this->postJson('/api/v1/auth/restaurant/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/restaurant/context', ['Authorization' => 'Bearer '.$login->json('token')])->assertOk()->assertJsonPath('locations.*.name', ['Burger Hub']);

        $this->assertSame(['restaurant_staff.invited', 'restaurant_staff.activated'], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(AuditEvent::query()->get()));
    }

    public function test_a_wrong_expired_or_withdrawn_link_says_the_same_thing(): void
    {
        $this->invite()->assertCreated();
        $user = RestaurantUser::query()->where('email', 'priya.nair@restaurant.example')->firstOrFail();
        $token = $this->linkToken($user);
        $invalid = ['code' => 'invitation_invalid', 'message' => 'This invitation link is invalid or has expired. Ask the restaurant to send a new one.'];
        $error = fn (TestResponse $r): array => array_diff_key($r->assertUnprocessable()->json('error'), ['request_id' => 1]);

        $this->assertSame($invalid, $error($this->accept(str_repeat('a', 64))));

        Carbon::setTestNow(now()->addHours(72)->addSecond());
        $this->assertSame($invalid, $error($this->accept($token)), 'expired');
        Carbon::setTestNow(now()->subHours(1));

        // Withdrawn: the owner revokes the open invitation.
        $this->actingAsPrincipal($this->owner);
        $this->deleteJson($this->url('/'.$this->membershipOf($user, $this->organization)->public_id))->assertOk()->assertJsonPath('status', 'REVOKED');
        $this->assertSame($invalid, $error($this->accept($token)), 'withdrawn');

        $this->assertSame(StaffStatus::Invited, $user->refresh()->status);
        $this->assertSame([], $this->assignments($user));
    }

    public function test_sending_the_invitation_again_cancels_the_earlier_link(): void
    {
        $id = $this->invite()->assertCreated()->json('id');
        $user = RestaurantUser::query()->where('email', 'priya.nair@restaurant.example')->firstOrFail();
        $first = $this->linkToken($user);

        Notification::fake();
        $this->postJson($this->url('/'.$id.'/invitation'))->assertOk()->assertJsonPath('message', 'A new invitation link has been sent. Earlier links no longer work.');
        $second = $this->linkToken($user);

        $this->assertNotSame($first, $second);
        $this->accept($first)->assertUnprocessable()->assertJsonPath('error.code', 'invitation_invalid');
        $this->accept($second)->assertOk();

        $this->actingAsPrincipal($this->owner);
        $this->postJson($this->url('/'.$id.'/invitation'))->assertConflict()->assertJsonPath('error.code', 'not_invited');
    }

    public function test_someone_who_already_has_an_account_joins_without_a_new_password_and_keeps_their_name_private_until_then(): void
    {
        $elsewhere = $this->organization('Second Kitchen');
        $existing = $this->member($elsewhere, 'OWNER', user: RestaurantUser::factory()->create(['name' => 'Real Name', 'email' => 'chef@restaurant.example']));
        $hash = $existing->password;

        $this->invite(['name' => 'Chef (typed by the inviter)', 'email' => 'chef@restaurant.example', 'role' => 'VIEWER', 'all_locations' => true])->assertCreated()
            ->assertJsonPath('name', 'Chef (typed by the inviter)')->assertJsonPath('last_login_at', null);
        $this->assertSame(1, RestaurantUser::query()->where('email', 'chef@restaurant.example')->count(), 'no second account');
        $this->assertStringNotContainsString('Real Name', $this->getJson($this->url())->getContent());

        $this->accept($this->linkToken($existing), null)->assertOk()->assertJsonPath('restaurant', 'Riverside');

        $this->assertSame($hash, $existing->refresh()->password, 'the existing password is untouched');
        $this->actingAsPrincipal($existing);
        $this->assertSame(['Second Kitchen', 'Riverside'], $this->getJson('/api/v1/restaurant/context')->json('organizations.*.name'));

        $this->actingAsPrincipal($this->owner);
        $this->assertContains('Real Name', $this->getJson($this->url())->json('data.*.name'), 'after accepting, the member is shown under their own name');
    }

    public function test_invitations_are_validated(): void
    {
        $viewer = $this->member($this->organization, 'VIEWER');
        $foreign = $this->location($this->organization('Second Kitchen'), 'Pizza Point');
        $refused = fn (array $overrides, string $field) => $this->invite($overrides)->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details.fields');

        $refused(['email' => $viewer->email], 'email');                          // already a member
        $refused(['email' => 'not-an-email'], 'email');
        $refused(['name' => 'P'], 'name');
        $refused(['name' => '<b>Priya</b>'], 'name');
        $refused(['role' => 'CHEF'], 'role');
        $refused(['role' => 'SUPER_ADMIN'], 'role');                              // an administrator role can never be granted here
        $refused(['all_locations' => 'sometimes'], 'all_locations');
        $refused(['location_ids' => []], 'location_ids');
        $refused(['location_ids' => [$foreign->public_id]], 'location_ids');
        $refused(['location_ids' => [$this->burger->public_id, $foreign->public_id]], 'location_ids');
        $refused(['location_ids' => ['1']], 'location_ids.0');

        $this->assertSame(2, RestaurantMembership::query()->count());
        Notification::assertNothingSent();

        $closed = RestaurantUser::factory()->status(StaffStatus::Disabled)->create(['email' => 'closed@restaurant.example']);
        $refused(['email' => $closed->email], 'email');
    }

    public function test_accepting_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->accept(str_repeat((string) $attempt, 64))->assertUnprocessable();
        }

        $this->accept(str_repeat('9', 64))->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    // ───────────────────────────── Role, access, status ─────────────────────────────

    public function test_a_role_change_takes_effect_through_the_derived_assignments(): void
    {
        $manager = $this->member($this->organization, 'MANAGER');
        $id = $this->membershipOf($manager, $this->organization)->public_id;
        $this->assertSame(['MANAGER organization '.$this->organization->public_id], $this->assignments($manager));

        $this->patchJson($this->url('/'.$id), ['version' => 1, 'role' => 'VIEWER', 'reason' => 'Moved to the accounts team'])->assertOk()
            ->assertJsonPath('role.code', 'VIEWER')->assertJsonPath('version', 2)->assertJsonPath('status', 'ACTIVE');
        $this->assertSame(['VIEWER organization '.$this->organization->public_id], $this->assignments($manager));

        $event = AuditEvent::query()->sole();
        $this->assertSame('restaurant_staff.role_changed', $event->action);
        $this->assertEquals(['role' => ['from' => 'MANAGER', 'to' => 'VIEWER']], $event->changes);
        $this->assertSame('Moved to the accounts team', $event->reason);

        $this->patchJson($this->url('/'.$id), ['version' => 1, 'role' => 'OWNER'])->assertConflict()->assertJsonPath('error.code', 'stale_update');
        $this->patchJson($this->url('/'.$id), ['version' => 2, 'role' => 'CHEF'])->assertUnprocessable();
        $this->patchJson($this->url('/'.$id), ['version' => 2, 'role' => 'VIEWER'])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson($this->url('/'.$id), ['role' => 'OWNER'])->assertUnprocessable();
        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_location_access_is_explicit_and_stays_inside_the_organization(): void
    {
        $manager = $this->member($this->organization, 'MANAGER');
        $id = $this->membershipOf($manager, $this->organization)->public_id;
        $foreign = $this->location($this->organization('Second Kitchen'), 'Pizza Point');

        $this->patchJson($this->url('/'.$id), ['version' => 1, 'location_ids' => [$this->burger->public_id]])->assertOk()
            ->assertJsonPath('all_locations', false)->assertJsonPath('locations.*.name', ['Burger Hub']);
        $this->assertSame(['MANAGER location '.$this->burger->public_id], $this->assignments($manager));

        $this->patchJson($this->url('/'.$id), ['version' => 2, 'location_ids' => [$this->burger->public_id, $this->brew->public_id]])->assertOk();
        $this->assertCount(2, $this->assignments($manager));

        $this->patchJson($this->url('/'.$id), ['version' => 3, 'location_ids' => [$this->burger->public_id, $foreign->public_id]])->assertUnprocessable()->assertJsonValidationErrorFor('location_ids', 'error.details.fields');
        $this->patchJson($this->url('/'.$id), ['version' => 3, 'all_locations' => false, 'location_ids' => []])->assertUnprocessable();
        $this->assertCount(2, $this->assignments($manager), 'a refused change leaves access as it was');

        $this->patchJson($this->url('/'.$id), ['version' => 3, 'all_locations' => true])->assertOk()->assertJsonPath('locations', []);
        $this->assertSame(['MANAGER organization '.$this->organization->public_id], $this->assignments($manager));

        $this->assertSame(['restaurant_staff.access_changed', 'restaurant_staff.access_changed', 'restaurant_staff.access_changed'], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertEquals(['from' => 'ALL', 'to' => ['Burger Hub']], AuditEvent::query()->orderBy('id')->first()->changes['locations']);
    }

    public function test_a_member_can_be_suspended_and_reactivated(): void
    {
        $manager = $this->member($this->organization, 'MANAGER', [$this->burger]);
        $id = $this->membershipOf($manager, $this->organization)->public_id;

        $this->patchJson($this->url('/'.$id), ['version' => 1, 'status' => 'SUSPENDED', 'reason' => 'On leave'])->assertOk()->assertJsonPath('status', 'SUSPENDED');
        $this->assertSame([], $this->assignments($manager));

        $this->patchJson($this->url('/'.$id), ['version' => 2, 'status' => 'ACTIVE'])->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertSame(['MANAGER location '.$this->burger->public_id], $this->assignments($manager));

        $this->patchJson($this->url('/'.$id), ['version' => 3, 'status' => 'REVOKED'])->assertUnprocessable();
        $this->patchJson($this->url('/'.$id), ['version' => 3, 'status' => 'INVITED'])->assertUnprocessable();
        $this->assertSame(['restaurant_staff.suspended', 'restaurant_staff.reactivated'], AuditEvent::query()->orderBy('id')->pluck('action')->all());

        // An open invitation cannot be "activated" by the inviter: only the invited person accepts.
        $invited = $this->invite()->json('id');
        $this->patchJson($this->url('/'.$invited), ['version' => 1, 'status' => 'ACTIVE'])->assertConflict()->assertJsonPath('error.code', 'not_accepted');
    }

    public function test_revoking_removes_access_at_once_and_ends_the_sessions_of_someone_with_no_other_restaurant(): void
    {
        $manager = $this->member($this->organization, 'MANAGER');
        $twoHomes = $this->member($this->organization, 'VIEWER');
        $this->member($this->organization('Second Kitchen'), 'VIEWER', user: $twoHomes);
        $manager->createToken('phone');
        $twoHomes->createToken('phone');

        $this->deleteJson($this->url('/'.$this->membershipOf($manager, $this->organization)->public_id), ['reason' => 'Left the company'])->assertOk()
            ->assertJsonPath('status', 'REVOKED')->assertJsonPath('revoked_at', fn ($at) => $at !== null);
        $this->assertSame([], $this->assignments($manager));
        $this->assertSame(0, $manager->tokens()->count(), 'signed out everywhere');

        $this->deleteJson($this->url('/'.$this->membershipOf($twoHomes, $this->organization)->public_id))->assertOk();
        $this->assertCount(1, $this->assignments($twoHomes), 'the other restaurant is untouched');
        $this->assertSame(1, $twoHomes->tokens()->count(), 'still a member elsewhere: the session stays');

        $event = AuditEvent::query()->orderBy('id')->first();
        $this->assertSame('restaurant_staff.revoked', $event->action);
        $this->assertSame('Left the company', $event->reason);
        $this->assertEquals(['status' => ['from' => 'ACTIVE', 'to' => 'REVOKED']], $event->changes);

        // Revoked members leave the list (and can be listed on request); the row stays for history.
        $this->assertSame([$this->owner->email], $this->getJson($this->url())->json('data.*.email'));
        $this->assertEqualsCanonicalizing([$manager->email, $twoHomes->email], $this->getJson($this->url().'?filter[status]=REVOKED')->json('data.*.email'));
        $this->patchJson($this->url('/'.$this->membershipOf($manager, $this->organization)->public_id), ['version' => 2, 'role' => 'VIEWER'])->assertConflict()->assertJsonPath('error.code', 'membership_revoked');

        // Inviting the person again gives a fresh invitation on the same membership.
        $this->invite(['email' => $manager->email, 'role' => 'VIEWER', 'all_locations' => true])->assertCreated()->assertJsonPath('status', 'INVITED')->assertJsonPath('revoked_at', null);
        $this->assertSame(1, RestaurantMembership::query()->where('restaurant_user_id', $manager->id)->count());
        $this->assertSame([], $this->assignments($manager));
    }

    // ───────────────────────────── Rules no permission overrides ─────────────────────────────

    public function test_nobody_changes_or_revokes_their_own_membership(): void
    {
        $this->member($this->organization, 'OWNER');
        $own = '/'.$this->membershipOf($this->owner, $this->organization)->public_id;

        foreach ([['role' => 'VIEWER'], ['status' => 'SUSPENDED'], ['location_ids' => [$this->burger->public_id]]] as $change) {
            $this->patchJson($this->url($own), ['version' => 1, ...$change])->assertConflict()->assertJsonPath('error.code', 'cannot_change_own_membership');
        }
        $this->deleteJson($this->url($own))->assertConflict()->assertJsonPath('error.code', 'cannot_change_own_membership');

        $this->assertSame(['OWNER organization '.$this->organization->public_id], $this->assignments($this->owner));
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_a_role_cannot_be_given_or_taken_by_someone_who_holds_less(): void
    {
        // A role that may manage staff but nothing else — it must not be able to make anyone an owner.
        app(RoleService::class)->define(PrincipalType::RestaurantUser, 'STAFF_ADMIN', 'Staff admin', [P::RestaurantStaffView, P::RestaurantStaffManage]);
        app(RoleService::class)->define(PrincipalType::RestaurantUser, 'STAFF_VIEWER', 'Staff viewer', [P::RestaurantStaffView]);
        $staffAdmin = $this->member($this->organization, 'STAFF_ADMIN');
        $viewer = $this->member($this->organization, 'STAFF_VIEWER');
        $this->actingAsPrincipal($staffAdmin);

        $this->invite(['role' => 'OWNER', 'all_locations' => true])->assertForbidden()->assertJsonPath('error.code', 'cannot_grant_beyond_own_permissions');
        $this->invite(['role' => 'MANAGER'])->assertForbidden()->assertJsonPath('error.code', 'cannot_grant_beyond_own_permissions');
        $this->patchJson($this->url('/'.$this->membershipOf($viewer, $this->organization)->public_id), ['version' => 1, 'role' => 'OWNER'])->assertForbidden();

        // The owner holds more than they do: not theirs to change or remove.
        $ownerMembership = '/'.$this->membershipOf($this->owner, $this->organization)->public_id;
        $this->patchJson($this->url($ownerMembership), ['version' => 1, 'role' => 'STAFF_VIEWER'])->assertForbidden()->assertJsonPath('error.code', 'cannot_grant_beyond_own_permissions');
        $this->deleteJson($this->url($ownerMembership))->assertForbidden();

        // Within what they hold, they can act.
        $this->invite(['role' => 'STAFF_VIEWER', 'all_locations' => true])->assertCreated();
        $this->deleteJson($this->url('/'.$this->membershipOf($viewer, $this->organization)->public_id))->assertOk();

        $this->assertSame(['OWNER organization '.$this->organization->public_id], $this->assignments($this->owner));
        $this->assertSame(1, RestaurantMembership::query()->whereHas('role', fn ($r) => $r->where('code', 'OWNER'))->count());
    }

    public function test_one_owner_can_replace_another_but_the_restaurant_never_ends_up_without_one(): void
    {
        $second = $this->member($this->organization, 'OWNER');
        $secondId = '/'.$this->membershipOf($second, $this->organization)->public_id;
        $firstId = '/'.$this->membershipOf($this->owner, $this->organization)->public_id;

        // Two owners: either may demote the other …
        $this->patchJson($this->url($secondId), ['version' => 1, 'role' => 'MANAGER'])->assertOk();
        $this->patchJson($this->url($secondId), ['version' => 2, 'role' => 'OWNER'])->assertOk();

        // … and then the roles are handed over.
        $this->actingAsPrincipal($second);
        $this->patchJson($this->url($firstId), ['version' => 1, 'role' => 'VIEWER'])->assertOk();
        $this->assertSame(['VIEWER organization '.$this->organization->public_id], $this->assignments($this->owner));

        // The remaining owner cannot remove themself (and nobody else may manage staff any more).
        $this->deleteJson($this->url($secondId))->assertConflict()->assertJsonPath('error.code', 'cannot_change_own_membership');
        $this->actingAsPrincipal($this->owner);
        $this->deleteJson($this->url($secondId))->assertForbidden();

        $this->assertSame(['OWNER organization '.$this->organization->public_id], $this->assignments($second));
    }

    public function test_the_role_catalogue_lists_the_restaurant_roles_only(): void
    {
        $roles = collect($this->getJson('/api/v1/restaurant/roles')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['OWNER', 'MANAGER', 'ORDER_STAFF', 'MENU_MANAGER', 'VIEWER'], $roles->keys()->all());
        $this->assertContains('restaurant.staff.manage', $roles['OWNER']['permissions']);
        $this->assertNotContains('restaurant.staff.manage', $roles['MANAGER']['permissions']);
        $this->assertStringNotContainsString('admin.', (string) json_encode($roles));
    }
}
