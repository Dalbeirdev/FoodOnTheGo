<?php

namespace Tests\Feature\Checkout;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\Promotion;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCheckout;
use Tests\TestCase;

/**
 * Promotions for platform administrators (Module 29): who may see and change them, how a promotion lives
 * (DRAFT → ACTIVE ⇄ PAUSED → DISABLED), what may be edited while customers use it, and the audit trail.
 */
class AdminPromotionApiTest extends TestCase
{
    use BuildsCheckout, RefreshDatabase;

    private AdminUser $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->seed(RoleSeeder::class);
        $this->super = $this->adminWithRole('SUPER_ADMIN');
    }

    private function adminWithRole(string $code, ?Market $scope = null): AdminUser
    {
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail(), $scope === null ? null : Scope::market($scope->public_id));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return ['code' => 'WELCOME100', 'name' => '₹100 off your first order', 'type' => 'FIXED_AMOUNT', 'scope' => 'PLATFORM', 'currency' => 'INR', 'amount_minor' => 10000, 'reason' => 'Launch campaign', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = [], ?AdminUser $as = null): TestResponse
    {
        $this->actingAsPrincipal($as ?? $this->super);

        return $this->postJson('/api/v1/admin/promotions', $this->body($overrides));
    }

    private function setStatus(array $promotion, string $status, ?int $version = null, string $reason = 'Campaign decision'): TestResponse
    {
        $this->actingAsPrincipal($this->super);

        return $this->postJson('/api/v1/admin/promotions/'.$promotion['id'].'/status', ['status' => $status, 'version' => $version ?? $promotion['version'], 'reason' => $reason]);
    }

    /* ------------------------------------------------------------------ who */

    public function test_only_administrators_with_the_permission_reach_promotions(): void
    {
        $id = $this->create()->assertCreated()->json('promotion.id');
        $this->app['auth']->forgetGuards();

        // Nobody, a customer and the owner of a restaurant: not administrators.
        $this->getJson('/api/v1/admin/promotions')->assertStatus(401);
        [, $customer] = $this->signedIn();
        $this->getJson('/api/v1/admin/promotions', $customer)->assertStatus(401);
        $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'MINE50']), $customer)->assertStatus(401);
        $this->actingAsPrincipal($this->staff());
        $this->getJson('/api/v1/admin/promotions')->assertStatus(401);
        $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'MINE50', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $this->burgerHub->public_id]))->assertStatus(401);
        // There is no promotion endpoint in the restaurant API: restaurant-created discounts are not approved.
        $this->getJson('/api/v1/restaurant/promotions')->assertNotFound();
        $this->postJson('/api/v1/restaurant/locations/'.$this->burgerHub->public_id.'/promotions', $this->body())->assertNotFound();

        // The seeded roles: support has no access, operations and analysts read, only the super admin writes.
        foreach (['SUPPORT_ADMIN', 'FINANCE_ADMIN', 'MODERATION_ADMIN', 'RESTAURANT_ONBOARDING'] as $role) {
            $this->actingAsPrincipal($this->adminWithRole($role));
            $this->getJson('/api/v1/admin/promotions')->assertForbidden();
            $this->getJson('/api/v1/admin/promotions/'.$id)->assertForbidden();
            $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'NOPE'.$role]))->assertForbidden();
        }
        foreach (['OPERATIONS_ADMIN', 'ANALYST'] as $role) {
            $this->actingAsPrincipal($this->adminWithRole($role));
            $this->getJson('/api/v1/admin/promotions')->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson('/api/v1/admin/promotions/'.$id)->assertOk()->assertJsonPath('promotion.code', 'WELCOME100');
            $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'NOPE'.$role]))->assertForbidden();
            $this->patchJson('/api/v1/admin/promotions/'.$id, ['name' => 'Changed', 'version' => 1, 'reason' => 'Trying'])->assertForbidden();
            $this->postJson('/api/v1/admin/promotions/'.$id.'/status', ['status' => 'ACTIVE', 'version' => 1, 'reason' => 'Trying'])->assertForbidden();
        }
        $this->assertSame(['DRAFT', 'WELCOME100', 1], [Promotion::query()->sole()->status->value, Promotion::query()->sole()->code, Promotion::query()->count()]);
    }

    public function test_a_permission_held_for_one_market_covers_that_markets_promotions_only(): void
    {
        $uae = Market::factory()->create(['country_code' => 'AE', 'slug' => 'ae', 'name' => 'United Arab Emirates', 'default_currency' => 'AED']);
        $platform = $this->create()->assertCreated()->json('promotion');
        $india = $this->create(['code' => 'INDIA10', 'scope' => 'MARKET', 'market_id' => $this->market->public_id, 'type' => 'PERCENTAGE', 'percent_basis_points' => 1000, 'amount_minor' => null, 'currency' => null])->assertCreated()->json('promotion');
        $restaurant = $this->create(['code' => 'BURGER50', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $this->burgerHub->public_id, 'amount_minor' => 5000])->assertCreated()->json('promotion');
        $abroad = $this->create(['code' => 'DUBAI10', 'scope' => 'MARKET', 'market_id' => $uae->public_id, 'currency' => 'AED', 'amount_minor' => 1000])->assertCreated()->json('promotion');
        // A restaurant promotion keeps its market, so the market's administrators see it.
        $this->assertSame([(string) $this->market->public_id, (string) $this->burgerHub->public_id], [$restaurant['market']['id'], $restaurant['location']['id']]);

        $local = $this->adminWith([Permission::AdminPromotionsView, Permission::AdminPromotionsManage], $this->market);
        $this->actingAsPrincipal($local);

        $this->assertEqualsCanonicalizing(['INDIA10', 'BURGER50'], array_column($this->getJson('/api/v1/admin/promotions')->assertOk()->json('data'), 'code'));
        $this->getJson('/api/v1/admin/promotions/'.$india['id'])->assertOk();
        $this->getJson('/api/v1/admin/promotions/'.$platform['id'])->assertForbidden();
        $this->getJson('/api/v1/admin/promotions/'.$abroad['id'])->assertForbidden();
        // Writing: inside the market yes; platform-wide, another market, or moving one out of the market, no.
        $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'LOCAL20', 'scope' => 'MARKET', 'market_id' => $this->market->public_id]))->assertCreated();
        $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'EVERYWHERE']))->assertForbidden();
        $this->postJson('/api/v1/admin/promotions', $this->body(['code' => 'DUBAI20', 'scope' => 'MARKET', 'market_id' => $uae->public_id, 'currency' => 'AED']))->assertForbidden();
        $this->patchJson('/api/v1/admin/promotions/'.$india['id'], ['scope' => 'PLATFORM', 'version' => 1, 'reason' => 'Widen it'])->assertForbidden();
        $this->patchJson('/api/v1/admin/promotions/'.$india['id'], ['scope' => 'MARKET', 'market_id' => $uae->public_id, 'version' => 1, 'reason' => 'Move it'])->assertForbidden();
        $this->patchJson('/api/v1/admin/promotions/'.$platform['id'], ['name' => 'Mine now', 'version' => 1, 'reason' => 'Take it'])->assertForbidden();
        $this->postJson('/api/v1/admin/promotions/'.$abroad['id'].'/status', ['status' => 'ACTIVE', 'version' => 1, 'reason' => 'Start it'])->assertForbidden();
        $this->patchJson('/api/v1/admin/promotions/'.$india['id'], ['name' => 'India 10 %', 'version' => 1, 'reason' => 'Clearer name'])->assertOk()->assertJsonPath('promotion.version', 2);

        // An administrator with no promotion permission anywhere is refused, not shown an empty list.
        $this->actingAsPrincipal($this->adminWith([Permission::AdminSystemView], $this->market));
        $this->getJson('/api/v1/admin/promotions')->assertForbidden();
    }

    /* ------------------------------------------------------------------ creating */

    public function test_a_promotion_is_created_as_a_draft_with_a_normalized_code_and_audited(): void
    {
        $promotion = $this->create(['code' => ' welcome100 ', 'description' => 'For new customers', 'minimum_spend_minor' => 30000, 'usage_limit' => 500, 'per_customer_limit' => 1, 'starts_at' => '2026-10-10T00:00:00+05:30', 'ends_at' => '2026-10-31T23:59:59+05:30'])->assertCreated()->json('promotion');

        $this->assertSame(['WELCOME100', 'DRAFT', 'DRAFT', 'FIXED_AMOUNT', 'PLATFORM', 'INR', 10000, null, 30000, 500, 1, 0, 0, 'PLATFORM', 'NOT_APPROVED', 1], [
            $promotion['code'], $promotion['status'], $promotion['effective_status'], $promotion['type'], $promotion['scope'], $promotion['currency'], $promotion['amount_minor'], $promotion['percent_basis_points'],
            $promotion['minimum_spend_minor'], $promotion['usage_limit'], $promotion['per_customer_limit'], $promotion['redeemed_count'], $promotion['held_count'], $promotion['funding_source'], $promotion['stacking'], $promotion['version'],
        ]);
        $this->assertSame(['2026-10-09T18:30:00+00:00', '2026-10-31T18:29:59+00:00'], [$promotion['starts_at'], $promotion['ends_at']]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $promotion['id']);

        $audit = AuditEvent::query()->where('action', 'promotion.created')->sole();
        $this->assertSame(['promotions', $promotion['id'], (string) $this->super->public_id, 'Launch campaign'], [$audit->target_type, $audit->target_public_id, $audit->actor_public_id, $audit->reason]);
        // Readable in the trail: a promotion code is not a secret (the key "code" alone would be redacted).
        $this->assertEquals(['from' => null, 'to' => 'WELCOME100'], $audit->changes['promotion_code']);

        // The code is unique whatever its case.
        $this->create(['code' => 'Welcome100'])->assertConflict()->assertJsonPath('error.code', 'promotion_code_taken');
        $this->assertSame(1, Promotion::query()->count());
    }

    public function test_what_does_not_make_a_coherent_promotion_is_refused(): void
    {
        $refused = fn (array $overrides, string $field) => $this->create(['code' => 'BAD'.substr(md5(json_encode($overrides)), 0, 8), ...$overrides])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        // Amounts are integers in minor units, rates integers in basis points: never a float, never a guess.
        $refused(['amount_minor' => 99.5], 'amount_minor');
        $refused(['amount_minor' => '100.00'], 'amount_minor');
        $refused(['amount_minor' => -100], 'amount_minor');
        $refused(['amount_minor' => 0], 'amount_minor');
        $refused(['type' => 'PERCENTAGE', 'amount_minor' => null, 'percent_basis_points' => 12.5], 'percent_basis_points');
        $refused(['type' => 'PERCENTAGE', 'amount_minor' => null, 'percent_basis_points' => 10001], 'percent_basis_points');
        // The parts must fit the type.
        $refused(['amount_minor' => null], 'amount_minor');
        $refused(['currency' => null], 'currency');
        $refused(['percent_basis_points' => 1000], 'percent_basis_points');
        $refused(['type' => 'PERCENTAGE', 'percent_basis_points' => 1000], 'amount_minor');
        $refused(['type' => 'PERCENTAGE', 'amount_minor' => null, 'currency' => null, 'percent_basis_points' => 1000, 'maximum_discount_minor' => 5000], 'currency');
        $refused(['starts_at' => '2026-10-10T00:00:00Z', 'ends_at' => '2026-10-09T00:00:00Z'], 'ends_at');
        // A type that is not approved does not exist.
        foreach (['BOGO', 'FREE_ITEM', 'CASHBACK', 'TIERED', 'STACKABLE'] as $type) {
            $refused(['type' => $type], 'type');
        }
        // The scope names exactly what it needs, and what it names must exist.
        $refused(['scope' => 'MARKET'], 'market_id');
        $refused(['scope' => 'RESTAURANT_LOCATION'], 'location_id');
        $refused(['scope' => 'RESTAURANT_ORGANIZATION'], 'organization_id');
        $refused(['scope' => 'RESTAURANT_LOCATION', 'location_id' => '0199c9d2-7f7b-7c8a-9d57-3d5e6f708192'], 'location_id');
        $refused(['scope' => 'EVERYWHERE'], 'scope');
        // What the server decides.
        $refused(['status' => 'ACTIVE'], 'status');
        $refused(['redeemed_count' => 5], 'redeemed_count');
        $refused(['version' => 3], 'version');
        $refused(['code' => 'two words'], 'code');
        $refused(['code' => '<b>X</b>'], 'code');

        $this->assertSame(0, Promotion::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'promotion.%')->count());
    }

    /* ------------------------------------------------------------------ life cycle */

    public function test_a_promotion_goes_from_draft_to_active_can_be_paused_and_ends_disabled(): void
    {
        $promotion = $this->create()->assertCreated()->json('promotion');

        // A reason and the version that was looked at are needed for every step.
        $this->postJson('/api/v1/admin/promotions/'.$promotion['id'].'/status', ['status' => 'ACTIVE', 'version' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/admin/promotions/'.$promotion['id'].'/status', ['status' => 'SCHEDULED', 'version' => 1, 'reason' => 'Not a stored state'])->assertUnprocessable();
        $this->setStatus($promotion, 'ACTIVE', 7)->assertConflict()->assertJsonPath('error.code', 'stale_update')->assertJsonPath('error.details.current_version', 1);
        $this->setStatus($promotion, 'PAUSED')->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');

        $active = $this->setStatus($promotion, 'ACTIVE', null, 'Campaign starts')->assertOk()->json('promotion');
        $this->assertSame(['ACTIVE', 'ACTIVE', 2], [$active['status'], $active['effective_status'], $active['version']]);
        // Saying it again changes nothing.
        $this->setStatus($active, 'ACTIVE')->assertOk()->assertJsonPath('promotion.version', 2);
        $this->setStatus($active, 'DRAFT')->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');
        $paused = $this->setStatus($active, 'PAUSED', null, 'Budget review')->assertOk()->json('promotion');
        $resumed = $this->setStatus($paused, 'ACTIVE', null, 'Budget approved')->assertOk()->json('promotion');
        $disabled = $this->setStatus($resumed, 'DISABLED', null, 'Campaign over')->assertOk()->json('promotion');
        $this->assertSame(['DISABLED', 5], [$disabled['status'], $disabled['version']]);
        // Disabled is final: it is not switched on again and not edited.
        foreach (['ACTIVE', 'PAUSED', 'DRAFT'] as $status) {
            $this->setStatus($disabled, $status)->assertConflict()->assertJsonPath('error.code', 'invalid_status_transition');
        }
        $this->patchJson('/api/v1/admin/promotions/'.$promotion['id'], ['name' => 'Again', 'version' => 5, 'reason' => 'Reuse it'])->assertConflict()->assertJsonPath('error.code', 'promotion_disabled');

        $trail = AuditEvent::query()->where('action', 'promotion.status_changed')->orderBy('id')->get();
        $this->assertSame([['DRAFT', 'ACTIVE', 'Campaign starts'], ['ACTIVE', 'PAUSED', 'Budget review'], ['PAUSED', 'ACTIVE', 'Budget approved'], ['ACTIVE', 'DISABLED', 'Campaign over']], $trail->map(fn (AuditEvent $e): array => [$e->changes['status']['from'], $e->changes['status']['to'], $e->reason])->all());
    }

    public function test_scheduled_and_expired_follow_from_the_server_clock(): void
    {
        $later = $this->create(['code' => 'LATER', 'starts_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('promotion');
        $ended = $this->create(['code' => 'ENDED', 'starts_at' => now()->subDays(3)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])->assertCreated()->json('promotion');
        $running = $this->create(['code' => 'RUNNING', 'ends_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('promotion');
        $this->create(['code' => 'DRAFTED'])->assertCreated();
        foreach ([$later, $ended, $running] as $promotion) {
            $this->setStatus($promotion, 'ACTIVE')->assertOk();
        }

        $by = fn (string $query): array => array_column($this->getJson('/api/v1/admin/promotions?sort=code&'.$query)->assertOk()->json('data'), 'code');
        $states = collect($this->getJson('/api/v1/admin/promotions?sort=code')->json('data'))->pluck('effective_status', 'code')->all();
        $this->assertSame(['DRAFTED' => 'DRAFT', 'ENDED' => 'EXPIRED', 'LATER' => 'SCHEDULED', 'RUNNING' => 'ACTIVE'], $states);
        $this->assertSame([['LATER'], ['ENDED'], ['RUNNING'], ['DRAFTED']], [$by('filter[status]=SCHEDULED'), $by('filter[status]=EXPIRED'), $by('filter[status]=ACTIVE'), $by('filter[status]=DRAFT')]);
        $this->getJson('/api/v1/admin/promotions?filter[status]=WHATEVER')->assertUnprocessable();

        // Two days on, without anybody touching a row: what was scheduled runs, what ran is over.
        $this->travel(2)->days();
        $states = collect($this->getJson('/api/v1/admin/promotions?sort=code')->json('data'))->pluck('effective_status', 'code')->all();
        $this->assertSame(['DRAFTED' => 'DRAFT', 'ENDED' => 'EXPIRED', 'LATER' => 'ACTIVE', 'RUNNING' => 'EXPIRED'], $states);
    }

    public function test_the_list_can_be_searched_filtered_sorted_and_paged(): void
    {
        [$pizza] = $this->otherRestaurant();
        $this->create(['code' => 'WELCOME100', 'name' => 'Welcome offer']);
        $this->create(['code' => 'INDIA10', 'name' => 'India ten percent', 'scope' => 'MARKET', 'market_id' => $this->market->public_id, 'type' => 'PERCENTAGE', 'percent_basis_points' => 1000, 'amount_minor' => null, 'currency' => null, 'starts_at' => '2026-11-01T00:00:00Z', 'ends_at' => '2026-11-30T00:00:00Z']);
        $this->create(['code' => 'BURGER50', 'name' => 'Burger Hub special', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $this->burgerHub->public_id, 'amount_minor' => 5000]);
        $this->create(['code' => 'PIZZA50', 'name' => 'Pizza Point special', 'scope' => 'RESTAURANT_LOCATION', 'location_id' => $pizza->public_id, 'amount_minor' => 5000]);
        $by = fn (string $query): array => array_column($this->getJson('/api/v1/admin/promotions?sort=code&'.$query)->assertOk()->json('data'), 'code');

        $this->assertSame(['BURGER50', 'PIZZA50'], $by('q=special'));
        $this->assertSame(['WELCOME100'], $by('q=welcome'));
        $this->assertSame([], $by('q='.rawurlencode('%')), 'a wildcard typed by somebody is a character, not a pattern');
        $this->assertSame(['INDIA10'], $by('filter[type]=PERCENTAGE'));
        $this->assertSame(['BURGER50', 'PIZZA50'], $by('filter[scope]=RESTAURANT_LOCATION'));
        $this->assertSame(['BURGER50', 'INDIA10', 'PIZZA50'], $by('filter[market]='.$this->market->public_id));
        $this->assertSame(['PIZZA50'], $by('filter[restaurant]='.$pizza->public_id));
        // Usable on a day: INDIA10 only in November.
        $this->assertSame(['BURGER50', 'PIZZA50', 'WELCOME100'], $by('filter[date]=2026-10-15'));
        $this->assertSame(['BURGER50', 'INDIA10', 'PIZZA50', 'WELCOME100'], $by('filter[date]=2026-11-15'));
        $this->getJson('/api/v1/admin/promotions?filter[date]=tomorrow')->assertUnprocessable();
        $this->getJson('/api/v1/admin/promotions?filter[market]=1')->assertUnprocessable();

        $page = $this->getJson('/api/v1/admin/promotions?sort=-code&page[size]=3&page[number]=2')->assertOk();
        $this->assertSame([['BURGER50'], 4, 2, 2], [array_column($page->json('data'), 'code'), $page->json('meta.total'), $page->json('meta.current_page'), $page->json('meta.last_page')]);
    }

    /* ------------------------------------------------------------------ editing */

    public function test_a_draft_can_be_rewritten_and_every_change_is_versioned_and_audited(): void
    {
        $promotion = $this->create()->assertCreated()->json('promotion');
        $url = '/api/v1/admin/promotions/'.$promotion['id'];

        $this->patchJson($url, ['amount_minor' => 15000])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['version', 'reason']]]]);
        $this->patchJson($url, ['amount_minor' => 15000, 'version' => 9, 'reason' => 'Bigger'])->assertConflict()->assertJsonPath('error.code', 'stale_update');
        $changed = $this->patchJson($url, ['amount_minor' => 15000, 'minimum_spend_minor' => 60000, 'name' => '₹150 off', 'version' => 1, 'reason' => 'Bigger offer'])->assertOk()->json('promotion');
        $this->assertSame([15000, 60000, '₹150 off', 2], [$changed['amount_minor'], $changed['minimum_spend_minor'], $changed['name'], $changed['version']]);

        $audit = AuditEvent::query()->where('action', 'promotion.updated')->sole();
        $this->assertSame('Bigger offer', $audit->reason);
        $this->assertEquals(['from' => 10000, 'to' => 15000], $audit->changes['amount_minor']);
        $this->assertEquals(['from' => null, 'to' => 60000], $audit->changes['minimum_spend_minor']);

        // A change that changes nothing is not a new version and not an audit entry.
        $this->patchJson($url, ['amount_minor' => 15000, 'version' => 2, 'reason' => 'Same again'])->assertOk()->assertJsonPath('promotion.version', 2);
        $this->assertSame(1, AuditEvent::query()->where('action', 'promotion.updated')->count());
        // Turning it into a percentage: the parts have to fit, as when creating.
        $this->patchJson($url, ['type' => 'PERCENTAGE', 'percent_basis_points' => 2000, 'version' => 2, 'reason' => 'Percent instead'])->assertUnprocessable();
        $percent = $this->patchJson($url, ['type' => 'PERCENTAGE', 'percent_basis_points' => 2000, 'amount_minor' => null, 'maximum_discount_minor' => 20000, 'version' => 2, 'reason' => 'Percent instead'])->assertOk()->json('promotion');
        $this->assertSame(['PERCENTAGE', 2000, null, 20000, 3], [$percent['type'], $percent['percent_basis_points'], $percent['amount_minor'], $percent['maximum_discount_minor'], $percent['version']]);
        // Its code can change while it is a draft — but not to one that is taken.
        $this->create(['code' => 'TAKEN'])->assertCreated();
        $this->patchJson($url, ['code' => 'taken', 'version' => 3, 'reason' => 'Rename'])->assertConflict()->assertJsonPath('error.code', 'promotion_code_taken');
        $this->patchJson($url, ['code' => 'fresh20', 'version' => 3, 'reason' => 'Rename'])->assertOk()->assertJsonPath('promotion.code', 'FRESH20');
        $this->assertEquals(['from' => 'WELCOME100', 'to' => 'FRESH20'], AuditEvent::query()->where('action', 'promotion.updated')->orderByDesc('id')->first()->changes['promotion_code']);
    }

    public function test_a_promotion_customers_are_using_is_not_rewritten_silently(): void
    {
        $promotion = $this->setStatus($this->create()->json('promotion'), 'ACTIVE')->assertOk()->json('promotion');
        $url = '/api/v1/admin/promotions/'.$promotion['id'];
        $live = fn (array $change) => $this->patchJson($url, [...$change, 'version' => 2, 'reason' => 'Change while live'])->assertConflict()->assertJsonPath('error.code', 'promotion_live_edit_refused');

        // The discount, the currency, the code, the type, the scope and the start are frozen while it is live.
        $live(['amount_minor' => 50000])->assertJsonPath('error.details.field', 'amount_minor');
        $live(['minimum_spend_minor' => 100]);
        $live(['code' => 'OTHERCODE']);
        $live(['currency' => 'USD']);
        $live(['type' => 'PERCENTAGE', 'percent_basis_points' => 9000, 'amount_minor' => null]);
        $live(['scope' => 'MARKET', 'market_id' => $this->market->public_id]);
        $live(['starts_at' => '2026-12-01T00:00:00Z']);
        $this->assertSame([10000, 2], [(int) Promotion::query()->sole()->amount_minor, (int) Promotion::query()->sole()->version]);

        // Sending back what is stored — the same start, written with another offset — is not a change and is not refused.
        $this->patchJson($url, ['amount_minor' => 10000, 'currency' => 'inr', 'code' => 'welcome100', 'starts_at' => null, 'version' => 2, 'reason' => 'Unchanged'])->assertOk()->assertJsonPath('promotion.version', 2);
        // Its name, its end and its limits may change: none of them changes what a customer is charged.
        $ok = $this->patchJson($url, ['name' => 'Welcome offer', 'ends_at' => '2026-12-31T18:30:00Z', 'usage_limit' => 1000, 'per_customer_limit' => 2, 'version' => 2, 'reason' => 'Extend the campaign'])->assertOk()->json('promotion');
        $this->assertSame(['Welcome offer', '2026-12-31T18:30:00+00:00', 1000, 2, 3], [$ok['name'], $ok['ends_at'], $ok['usage_limit'], $ok['per_customer_limit'], $ok['version']]);

        // To change the discount it is paused first — then it can be, and every step is in the audit log.
        $paused = $this->setStatus($ok, 'PAUSED', null, 'Change the discount')->assertOk()->json('promotion');
        $this->patchJson($url, ['amount_minor' => 5000, 'version' => $paused['version'], 'reason' => 'Smaller discount'])->assertOk()->assertJsonPath('promotion.amount_minor', 5000);
        $this->assertSame(['promotion.created', 'promotion.status_changed', 'promotion.updated', 'promotion.status_changed', 'promotion.updated'], AuditEvent::query()->where('action', 'like', 'promotion.%')->orderBy('id')->pluck('action')->all());

        // A scheduled promotion is announced: the same rule.
        $scheduled = $this->setStatus($this->create(['code' => 'SOON', 'starts_at' => now()->addDay()->toIso8601String()])->json('promotion'), 'ACTIVE')->json('promotion');
        $this->assertSame('SCHEDULED', $scheduled['effective_status']);
        $this->patchJson('/api/v1/admin/promotions/'.$scheduled['id'], ['amount_minor' => 1, 'version' => 2, 'reason' => 'Change'])->assertConflict()->assertJsonPath('error.code', 'promotion_live_edit_refused');
    }

    /* ------------------------------------------------------------------ what customers see of it */

    public function test_what_an_administrator_does_reaches_checkout_at_once(): void
    {
        [, $asha] = $this->customerReadyForCheckout();   // ₹500
        $checkout = $this->openCheckout($asha)->assertCreated()->json('checkout');
        $apply = fn (string $id) => $this->postJson('/api/v1/checkouts/'.$id.'/promotion', ['code' => 'welcome100'], $this->using($asha));

        // A draft does not exist for customers.
        $promotion = $this->create(['usage_limit' => 5])->assertCreated()->json('promotion');
        $apply($checkout['id'])->assertUnprocessable()->assertJsonPath('error.code', 'promotion_not_found');

        $promotion = $this->setStatus($promotion, 'ACTIVE')->assertOk()->json('promotion');
        $apply($checkout['id'])->assertOk()->assertJsonPath('checkout.pricing.total', $this->inr(40000));
        $ready = $this->confirmCheckout($asha, $this->showCheckout($asha, $checkout['id'])->json('checkout'))->assertOk()->json('checkout');

        // The administrator sees one use held by a confirmed checkout — a count, never whose.
        $this->actingAsPrincipal($this->super);
        $seen = $this->getJson('/api/v1/admin/promotions/'.$promotion['id'])->assertOk();
        $this->assertSame([1, 0, 5], [$seen->json('promotion.held_count'), $seen->json('promotion.redeemed_count'), $seen->json('promotion.usage_limit')]);
        $this->assertStringNotContainsString('+9198', $seen->getContent());
        $this->assertStringNotContainsString($ready['id'], $seen->getContent());

        // Paused: the confirmed checkout cannot be paid at the discounted total any more.
        $this->setStatus($promotion, 'PAUSED', null, 'Budget review')->assertOk();
        $stale = $this->showCheckout($asha, $ready['id'])->assertOk()->json('checkout');
        $this->assertSame(['STALE', 'PROMOTION_CHANGED', null], [$stale['status'], $stale['issues'][0]['code'], $stale['payment']]);
        $next = $this->openCheckout($asha)->assertCreated()->json('checkout');
        $this->assertSame([$this->inr(50000), null], [$next['pricing']['total'], $next['promotion']]);
        $this->actingAsPrincipal($this->super);
        $this->getJson('/api/v1/admin/promotions/'.$promotion['id'])->assertJsonPath('promotion.held_count', 0);
    }
}
