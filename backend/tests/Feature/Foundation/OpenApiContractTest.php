<?php

namespace Tests\Feature\Foundation;

use App\Auth\AccessControl;
use App\Enums\Permission;
use App\Models\Market;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Keeps openapi/openapi.json honest: the documented paths must be exactly the registered /api/v1 routes,
 * and real responses must match the documented schemas.
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

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
        $admin = User::factory()->admin()->create();
        app(AccessControl::class)->grant($admin, Permission::AdminMarketsView);

        $this->assertMatchesSchema('Health', $this->getJson('/api/v1/health')->assertOk()->json());
        $this->assertMatchesSchema('Readiness', $this->getJson('/api/v1/ready')->assertOk()->json());
        $this->assertMatchesSchema('Market', $this->getJson('/api/v1/markets/current')->assertOk()->json());
        $this->assertMatchesSchema('ClientConfig', $this->getJson('/api/v1/config')->assertOk()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/markets/current?country=ZZ')->assertNotFound()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/markets/current?country=zz')->assertUnprocessable()->json());
        $this->assertMatchesSchema('Error', $this->getJson('/api/v1/auth/me')->assertUnauthorized()->json());

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha Verma', 'identity' => 'asha@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => true,
        ])->assertCreated();
        $this->assertMatchesSchema('TokenResponse', $register->json());

        Sanctum::actingAs($admin);
        $this->assertMatchesSchema('User', $this->getJson('/api/v1/auth/me')->assertOk()->json());
        $this->assertMatchesSchema('MarketPage', $this->getJson('/api/v1/admin/markets')->assertOk()->json());
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

        if ($types !== [] && ! in_array($actual, $types, true)) {
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
