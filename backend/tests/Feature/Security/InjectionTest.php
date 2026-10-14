<?php

namespace Tests\Feature\Security;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\Concerns\WalksRoutes;
use Tests\Support\BuildsSupport;
use Tests\TestCase;

/**
 * Module 39 — injection and hostile input (OWASP A05:2025 Injection, API8:2023): every read route of every surface,
 * called by a principal who may use it, survives hostile query strings (SQL fragments, operators, out-of-range and
 * array values) with a refusal or an answer — never a crash; script and SQL text is stored and returned as plain JSON
 * text; media paths cannot leave the media folders.
 */
class InjectionTest extends TestCase
{
    use BuildsSupport, RefreshDatabase, WalksRoutes;

    private const HOSTILE = [
        "q=' OR '1'='1' --&search=%27%3B%20drop%20table%20orders%3B--&sort=name;drop table orders&direction=desc,(select 1)"
            ."&filter[status]=' OR 1=1 --&filter[id]=1 union select password from admin_users&page=-1&per_page=99999999999999999999"
            ."&from=2026-13-45&to='&market=../../etc/passwd&location=' OR 1=1&cursor=%00%27&after_sequence=1e309&limit=-5&status=<script>",
        'q[]=x&sort[]=y&page[]=1&filter=plain&per_page[]=5&from[]=2026-10-01&market[$ne]=x&location[]=y&status[]=OPEN',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_every_read_route_survives_hostile_query_strings(): void
    {
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $owner = $this->member($this->organization, 'OWNER');
        [$customer] = $this->signedIn('+919876543210');
        $actors = ['ADMIN' => $admin, 'RESTAURANT' => $owner, 'CUSTOMER' => $customer, 'PUBLIC_OR_CUSTOMER' => $customer, 'ANY_PRINCIPAL' => $customer, 'ANY_STAFF' => $owner];

        $crashes = [];
        $walked = 0;
        foreach ($this->registeredRoutes() as $r) {
            $class = $r['row']['class'] ?? null;
            if ($r['method'] !== 'GET' || in_array($class, [null, 'DEVELOPMENT_ONLY', 'PROVIDER_WEBHOOK'], true)) {
                continue;
            }
            foreach (self::HOSTILE as $query) {
                Cache::flush();
                $this->app['auth']->forgetGuards();
                if (isset($actors[$class])) {
                    $this->actingAsPrincipal($actors[$class]);
                }
                $response = $this->getJson($r['url'].'?'.$query);
                $walked++;
                if ($response->status() >= 500) {
                    $crashes[] = "{$class} GET {$r['uri']} → {$response->status()} ".substr((string) $response->json('error.code'), 0, 40);
                }
            }
        }
        $this->assertGreaterThan(300, $walked);
        $this->assertSame([], $crashes);
        $this->assertTrue(Schema::hasTable('orders') && Schema::hasTable('admin_users'));
    }

    public function test_script_and_sql_text_is_stored_and_returned_as_plain_json_text(): void
    {
        [, $headers] = $this->signedIn('+919876543210');
        $script = '<script>alert(document.cookie)</script><img src=x onerror=alert(1)>';
        $sql = "Robert'); DROP TABLE orders;--";

        // A display name refuses markup outright (validation), it is not "cleaned".
        $this->patchJson('/api/v1/customer/profile', ['name' => $script], $this->using($headers))->assertUnprocessable()->assertJsonValidationErrors(['name'], 'error.details.fields');

        $case = $this->customerCase($headers, ['subject' => $sql, 'message' => $script.' '.$sql]);
        $read = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'])->assertOk();
        $this->assertSame($sql, $read->json('case.subject'));
        $messages = $this->customerSupport($headers, 'GET', '/cases/'.$case['id'].'/messages')->assertOk();
        $this->assertStringContainsString($script.' '.$sql, (string) json_encode($messages->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'stored exactly, encoded only by JSON');
        $this->assertStringContainsString('application/json', (string) $read->headers->get('Content-Type'));
        $read->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertTrue(Schema::hasTable('orders'));

        // Searching for the same strings is a search, not a statement.
        $this->getJson('/api/v1/restaurants?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/restaurants?q='.urlencode($sql))->assertOk();
    }

    public function test_media_paths_cannot_leave_the_media_folders(): void
    {
        foreach (['../../.env', '..%2F..%2F.env', 'menu/../../../.env', 'menu/%2e%2e/%2e%2e/.env', 'avatars/../../storage/logs/laravel.log', '%00.jpg', 'menu/'.str_repeat('a', 36).'/x.php', '/etc/passwd'] as $path) {
            Cache::flush();
            $response = $this->get('/api/v1/media/'.$path, ['Accept' => 'application/json']);
            $this->assertContains($response->status(), [404, 400], $path);
            $this->assertStringNotContainsString('APP_KEY', (string) $response->getContent(), $path);
        }
    }
}
