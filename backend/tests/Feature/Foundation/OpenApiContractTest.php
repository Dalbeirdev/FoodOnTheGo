<?php

namespace Tests\Feature\Foundation;

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AdminUser;
use App\Models\Market;
use App\Services\Rbac\RoleService;
use Database\Factories\AdminUserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsGeography;
use Tests\TestCase;

/**
 * Keeps openapi/openapi.json honest: the documented paths must be exactly the registered /api/v1 routes,
 * and real responses must match the documented schemas.
 */
class OpenApiContractTest extends TestCase
{
    use BuildsGeography, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spec = json_decode((string) File::get(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_documented_operations_are_exactly_the_registered_v1_routes(): void
    {
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $registered[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
                }
            }
        }

        $documented = [];
        foreach ($this->spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        sort($registered);
        sort($documented);
        $this->assertSame($registered, $documented);
    }

    public function test_real_responses_match_their_documented_schemas(): void
    {
        Market::factory()->india()->create();
        $admin = AdminUser::factory()->create();
        $roles = app(RoleService::class);
        $roles->assign($admin, $roles->define(PrincipalType::AdminUser, 'MARKET_VIEWER', 'Market viewer', [Permission::AdminMarketsView]));

        $this->assertMatchesSchema('Health', $this->getJson('/api/v1/health')->assertOk()->json());
        $this->assertMatchesSchema('Readiness', $this->getJson('/api/v1/ready')->assertOk()->json());
        $this->assertMatchesSchema('Market', $this->getJson('/api/v1/markets/current')->assertOk()->json());
        $this->assertMatchesSchema('ClientConfig', $this->getJson('/api/v1/config')->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/markets/current?country=ZZ')->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/markets/current?country=zz')->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/auth/me')->assertUnauthorized()->json());

        // Customer: request a code, verify it, read the identity and the session list.
        $challenge = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '98765 43210'])->assertOk();
        $this->assertMatchesSchema('OtpChallenge', $challenge->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $challenge->json('challenge_id'), 'code' => '000000'])->assertUnprocessable()->json());
        $signedIn = $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $challenge->json('challenge_id'), 'code' => '123456', 'device_name' => 'web'])->assertCreated();
        $this->assertMatchesSchema('CustomerSession', $signedIn->json());

        $customer = ['Authorization' => 'Bearer '.$signedIn->json('token')];
        $this->assertMatchesSchema('CustomerPrincipal', $this->getJson('/api/v1/auth/me', $customer)->assertOk()->json());
        $this->assertMatchesSchema('CustomerPrincipal', $this->patchJson('/api/v1/auth/customer/profile', ['name' => 'Asha Verma', 'accept_terms' => true], $customer)->assertOk()->json());
        $sessions = $this->getJson('/api/v1/auth/sessions', $customer)->assertOk()->json();
        $this->assertMatchesSchema('Session', $sessions[0]);

        // Admin: password sign-in, identity with roles and permissions, a protected collection.
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD])->assertOk();
        $this->assertMatchesSchema('StaffSession', $login->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'wrong-password-value'])->assertUnauthorized()->json());

        $staff = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->assertMatchesSchema('StaffPrincipal', $this->getJson('/api/v1/auth/me', $staff)->assertOk()->json());
        $this->assertMatchesSchema('MarketPage', $this->getJson('/api/v1/admin/markets', $staff)->assertOk()->json());
        $this->assertMatchesSchema('MfaSetup', $this->postJson('/api/v1/auth/mfa/totp/setup', [], $staff)->assertOk()->json());
    }

    public function test_market_and_geography_responses_match_their_documented_schemas(): void
    {
        $india = $this->india();
        $region = $this->region($india);
        $city = $this->city($region);
        $area = $this->area($city, [77.30, 28.50, 77.40, 28.60]);
        $corridor = $this->corridor($india, [[77.0, 28.55], [78.0, 28.55]]);
        $point = ['lat' => 28.55, 'lng' => 77.35];

        $this->assertMatchesSchema('MarketList', $this->getJson('/api/v1/markets')->assertOk()->json());
        $this->assertMatchesSchema('Coverage', $this->getJson('/api/v1/markets/current/coverage')->assertOk()->json());
        $this->assertMatchesSchema('Availability', $this->postJson('/api/v1/availability/location', $point)->assertOk()->assertJsonPath('supported', true)->json());
        $this->assertMatchesSchema('Availability', $this->postJson('/api/v1/availability/location', ['lat' => 48.85, 'lng' => 2.35])->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->postJson('/api/v1/availability/location', ['lat' => 99])->assertUnprocessable()->json());

        $this->actingAsPrincipal($this->adminWith(array_values(array_filter(Permission::cases(), fn (Permission $p) => str_starts_with($p->value, 'admin.')))));
        $m = '/api/v1/admin/markets/'.$india->public_id;

        $this->assertMatchesSchema('AdminMarket', $this->getJson($m)->assertOk()->json());
        $this->assertMatchesSchema('MarketConfiguration', $this->getJson($m.'/configuration')->assertOk()->json());
        $this->assertMatchesSchema('RegionPage', $this->getJson($m.'/regions')->assertOk()->json());
        $this->assertMatchesSchema('CityPage', $this->getJson($m.'/cities')->assertOk()->json());
        $this->assertMatchesSchema('ServiceAreaPage', $this->getJson($m.'/service-areas')->assertOk()->json());
        $this->assertMatchesSchema('RouteCorridorPage', $this->getJson($m.'/route-corridors')->assertOk()->json());
        $this->assertMatchesSchema('AdminMap', $this->getJson($m.'/map')->assertOk()->json());
        $this->assertMatchesSchema('AvailabilityDiagnostic', $this->postJson($m.'/availability-check', $point)->assertOk()->json());
        $this->assertMatchesSchema('City', $this->getJson('/api/v1/admin/cities/'.$city->public_id)->assertOk()->json());
        $this->assertMatchesSchema('ServiceArea', $this->getJson('/api/v1/admin/service-areas/'.$area->public_id)->assertOk()->json());
        $this->assertMatchesSchema('RouteCorridor', $this->getJson('/api/v1/admin/route-corridors/'.$corridor->public_id)->assertOk()->json());

        $this->assertMatchesSchema('Region', $this->postJson($m.'/regions', ['code' => 'IN-DL', 'name' => 'Delhi', 'type' => 'UNION_TERRITORY'])->assertCreated()->json());
        $this->assertMatchesSchema('Region', $this->patchJson('/api/v1/admin/regions/'.$region->public_id, ['version' => 1, 'name' => 'UP'])->assertOk()->json());
        $this->assertMatchesSchema('City', $this->postJson($m.'/cities', ['region_id' => $region->public_id, 'name' => 'Agra', 'latitude' => 27.17, 'longitude' => 78.0])->assertCreated()->json());
        $this->assertMatchesSchema('City', $this->patchJson('/api/v1/admin/cities/'.$city->public_id, ['version' => 1, 'status' => 'PILOT'])->assertOk()->json());
        $this->assertMatchesSchema('ServiceArea', $this->postJson($m.'/service-areas', ['city_id' => $city->public_id, 'name' => 'Second', 'geometry' => $this->square([77.5, 28.5, 77.6, 28.6])])->assertCreated()->json());
        $this->assertMatchesSchema('ServiceArea', $this->patchJson('/api/v1/admin/service-areas/'.$area->public_id, ['version' => 1, 'priority' => 3])->assertOk()->json());
        $this->assertMatchesSchema('RouteCorridor', $this->postJson($m.'/route-corridors', ['name' => 'Second', 'geometry' => ['type' => 'LineString', 'coordinates' => [[77.0, 28.0], [77.5, 28.5]]], 'corridor_width_meters' => 3000])->assertCreated()->json());
        $this->assertMatchesSchema('RouteCorridor', $this->patchJson('/api/v1/admin/route-corridors/'.$corridor->public_id, ['version' => 1, 'corridor_width_meters' => 6000])->assertOk()->json());
        $this->assertMatchesSchema('MarketConfiguration', $this->patchJson($m.'/configuration', ['version' => 1, 'reason' => 'Contract test', 'tax' => ['regime' => 'GST']])->assertOk()->json());
        $this->assertMatchesSchema('AdminMarket', $this->patchJson($m.'/features', ['version' => 1, 'features' => ['reviews' => false], 'reason' => 'Contract test'])->assertOk()->json());
        $this->assertMatchesSchema('AdminMarket', $this->patchJson($m, ['version' => 2, 'status' => 'PAUSED', 'reason' => 'Contract test'])->assertOk()->json());
        $this->assertMatchesSchema('SecurityEventPage', $this->getJson('/api/v1/admin/security-events')->assertOk()->json());
        $this->assertMatchesSchema('SecuritySummary', $this->getJson('/api/v1/admin/security/summary')->assertOk()->json());
        $this->assertMatchesSchema('AuditEventPage', $this->getJson('/api/v1/admin/audit-events')->assertOk()->assertJsonCount(11, 'data')->json());

        $this->assertMatchesSchema('Error', $this->patchJson($m, ['version' => 1, 'status' => 'ACTIVE', 'reason' => 'Stale'])->assertConflict()->json());
        $this->assertMatchesSchema('Error', $this->postJson($m.'/service-areas', ['city_id' => $city->public_id, 'name' => 'Bad', 'geometry' => ['type' => 'Point', 'coordinates' => [77, 28]]])->assertUnprocessable()->json());
    }

    private function assertMatchesSchema(string $name, mixed $value): void
    {
        $errors = $this->validate($this->spec['components']['schemas'][$name], $value, $name);

        $this->assertSame([], $errors, "Response does not match schema {$name}");
    }

    /**
     * Minimal JSON Schema check: $ref, type, enum, pattern, required, properties, additionalProperties, items.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validate(array $schema, mixed $value, string $path): array
    {
        if (isset($schema['$ref'])) {
            return $this->validate($this->spec['components']['schemas'][basename($schema['$ref'])], $value, $path);
        }

        $errors = [];
        $types = (array) ($schema['type'] ?? []);
        $actual = match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && ($value === [] || array_is_list($value)) => in_array('object', $types, true) && $value === [] ? 'object' : 'array',
            default => 'object',
        };

        // JSON Schema: every integer is also a number.
        if ($types !== [] && ! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
            return ["{$path}: expected ".implode('|', $types).", got {$actual}"];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: value not in enum";
        }
        if (isset($schema['pattern']) && is_string($value) && preg_match('/'.$schema['pattern'].'/', $value) !== 1) {
            $errors[] = "{$path}: does not match pattern";
        }

        if ($actual === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key}: missing";
                }
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    array_push($errors, ...$this->validate($schema['properties'][$key], $item, "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key}: undocumented property";
                }
            }
        }

        if ($actual === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($errors, ...$this->validate($schema['items'], $item, "{$path}[{$index}]"));
            }
        }

        return $errors;
    }
}
