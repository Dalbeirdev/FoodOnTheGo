<?php

namespace Tests\Feature\Analytics;

use App\Auth\AccessControl;
use App\Enums\Permission;
use App\Jobs\Analytics\GenerateReportExportJob;
use App\Models\AuditEvent;
use App\Models\ReportExport;
use App\Services\Analytics\Reports\ReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAnalytics;
use Tests\TestCase;

/**
 * Module 38 — report exports: asked for and built in the background, kept on private storage, handed to the person
 * who asked while they may still have it, gone when the time is up — and audited. A file carries no customer and no
 * formula. The 10 % commission is a TEST FIXTURE.
 */
class AnalyticsExportTest extends TestCase
{
    use BuildsAnalytics, RefreshDatabase;

    private const PERIOD = ['from' => '2026-10-01', 'to' => '2026-10-07'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->commissionPlan(1000);
        Storage::fake('local');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(mixed $as, string $audience, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($as);

        return $this->postJson('/api/v1/'.$audience.'/analytics/exports', $body);
    }

    private function fetch(mixed $as, string $audience, string $path = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->actingAsPrincipal($as);

        return $this->get('/api/v1/'.$audience.'/analytics/exports'.$path, ['Accept' => 'application/json']);
    }

    public function test_an_export_is_built_in_the_background_kept_privately_and_downloaded_by_its_requester(): void
    {
        $order = $this->completedOrderWith();
        $this->aggregate();
        $admin = $this->analyst();

        // Asking never waits for the file: a row QUEUED and a job on the queue.
        Queue::fake();
        $queued = $this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(202)->json('export');
        $this->assertSame(['QUEUED', 'orders', 'CSV', null, null, '2026-10-01', 'Asia/Kolkata'], [$queued['status'], $queued['report'], $queued['format'], $queued['download_path'], $queued['row_count'], $queued['period']['from'], $queued['period']['timezone']]);
        Queue::assertPushed(GenerateReportExportJob::class, 1);
        $this->fetch($admin, 'admin', '/'.$queued['id'].'/download')->assertStatus(409)->assertJsonPath('error.code', 'export_not_ready');
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The job builds it: READY, with an expiry, on the private disk.
        $record = ReportExport::query()->where('public_id', $queued['id'])->firstOrFail();
        (new GenerateReportExportJob((int) $record->getKey()))->handle(app(ReportExportService::class));
        $ready = $this->fetch($admin, 'admin', '/'.$queued['id'])->assertOk()->json('export');
        $this->assertSame(['READY', 1, '/api/v1/admin/analytics/exports/'.$queued['id'].'/download', 'foodonthego-orders-2026-10-01-to-2026-10-07.csv'], [$ready['status'], $ready['row_count'], $ready['download_path'], $ready['filename']]);
        $this->assertSame(24, (int) round((strtotime($ready['expires_at']) - strtotime($ready['completed_at'])) / 3600));
        $this->assertSame(['analytics-exports/'.$queued['id'].'.csv'], Storage::disk('local')->allFiles());
        // Where the file is never leaves the backend, and nothing public points at it.
        $this->assertStringNotContainsString('analytics-exports', json_encode($ready));
        $this->assertArrayNotHasKey('storage_reference', $ready);
        $this->assertSame([], array_values(array_filter(Storage::disk('public')->allFiles(), fn (string $f): bool => str_ends_with($f, '.csv'))));

        // The download: a CSV attachment that no cache may keep.
        $download = $this->fetch($admin, 'admin', '/'.$queued['id'].'/download')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $download->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="foodonthego-orders-2026-10-01-to-2026-10-07.csv"', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $csv = $download->streamedContent();
        $lines = explode("\r\n", trim($csv));
        $this->assertStringStartsWith("\xEF\xBB\xBF".'"FoodOnTheGo Orders report — 2026-10-01 to 2026-10-07 (Asia/Kolkata)"', $lines[0]);
        $this->assertSame('"Order number","Placed at","Status","Restaurant","Currency","Order total","Items","Pickup","Pickup method","Accepted at","Ready at","Completed at","Refund status","From a journey"', $lines[1]);
        $this->assertStringContainsString('"'.$order->order_number.'","2026-10-07 12:00","COMPLETED","Burger Hub","INR","500.00"', $lines[2]);
        // No customer in the file: an order is named by its number.
        foreach (['+9198', (string) $order->customer->name, (string) $order->customer->public_id, 'phone', 'email'] as $personal) {
            $this->assertStringNotContainsString($personal, $csv);
        }
        $this->assertSame(1, $this->fetch($admin, 'admin', '/'.$queued['id'])->json('export.download_count'));

        // Asking and downloading are on the audit trail: who, which report, which period.
        $asked = AuditEvent::query()->where('action', 'analytics.export_requested')->firstOrFail();
        $this->assertSame([$admin->public_id, 'report_exports', $queued['id'], 'orders', '2026-10-01..2026-10-07'], [$asked->actor_public_id, $asked->target_type, $asked->target_public_id, $asked->changes['export']['to'], $asked->changes['period']['to']]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'analytics.export_downloaded')->where('target_public_id', $queued['id'])->count());
        $list = $this->fetch($admin, 'admin')->assertOk()->json();
        $this->assertSame([[$queued['id']], 24], [array_column($list['data'], 'id'), $list['expiry_hours']]);
        $this->assertContains('financial_summary', array_column($list['reports'], 'key'));
    }

    public function test_an_export_belongs_to_the_person_who_asked_and_to_nobody_else(): void
    {
        $this->completedOrderWith();
        $this->aggregate();
        $admin = $this->analyst();
        $colleague = $this->analyst();
        $owner = $this->member($this->burgerHub->organization, 'OWNER');
        $partner = $this->member($this->burgerHub->organization, 'OWNER');
        $rival = $this->member($this->location($this->organization('Second Kitchen'), 'Pizza Point', [], 28.56, 77.36)->organization, 'OWNER');
        $mine = $this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(202)->json('export.id');
        $theirs = $this->ask($owner, 'restaurant', ['report' => 'orders', 'location' => $this->burgerHub->public_id] + self::PERIOD)->assertStatus(202)->json('export.id');
        // The queue runs at once in tests: both are ready.
        $this->assertSame(['READY', 'READY'], [$this->fetch($admin, 'admin', '/'.$mine)->json('export.status'), $this->fetch($owner, 'restaurant', '/'.$theirs)->json('export.status')]);

        // Another administrator — with the very same permissions — cannot see that it exists, let alone download it.
        foreach (['', '/download'] as $path) {
            $this->fetch($colleague, 'admin', '/'.$mine.$path)->assertStatus(404)->assertJsonPath('error.code', 'export_not_found');
            // A restaurant's export is not an administrator's and the other way round; a colleague's and a rival's neither.
            $this->fetch($admin, 'admin', '/'.$theirs.$path)->assertStatus(404);
            $this->fetch($owner, 'restaurant', '/'.$mine.$path)->assertStatus(404);
            $this->fetch($partner, 'restaurant', '/'.$theirs.$path)->assertStatus(404);
            $this->fetch($rival, 'restaurant', '/'.$theirs.$path)->assertStatus(404);
        }
        $this->assertSame([], $this->fetch($colleague, 'admin')->assertOk()->json('data'));
        $this->assertSame([], $this->fetch($partner, 'restaurant')->assertOk()->json('data'));
        $this->fetch($admin, 'admin', '/not-an-id')->assertStatus(404);

        // The restaurant's file holds its own orders; a location that is not theirs cannot be exported.
        $csv = $this->fetch($owner, 'restaurant', '/'.$theirs.'/download')->assertOk()->streamedContent();
        $this->assertStringContainsString('Burger Hub', $csv);
        $this->ask($rival, 'restaurant', ['report' => 'orders', 'location' => $this->burgerHub->public_id] + self::PERIOD)->assertStatus(404);
        $this->ask($owner, 'restaurant', ['report' => 'restaurant_performance'] + self::PERIOD)->assertStatus(422);
        $this->assertSame(0, DB::table('report_exports')->where('requested_by_restaurant_user_id', $rival->getKey())->count());
    }

    public function test_exporting_needs_its_permission_at_the_time_of_asking_and_again_at_the_time_of_downloading(): void
    {
        $this->completedOrderWith();
        $this->aggregate();
        $viewer = $this->adminWith([Permission::AdminAnalyticsView]);
        $analyst = $this->analyst([Permission::AdminFinanceView]);
        $finance = $this->analyst([Permission::AdminFinanceView, Permission::AdminFinanceExport, Permission::AdminSupportView, Permission::AdminPaymentsView, Permission::AdminRefundsView]);

        // Reading analytics is not exporting analytics.
        $this->ask($viewer, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(403);
        $this->fetch($viewer, 'admin')->assertStatus(403);
        // A financial report needs the finance export permission on top; a support or payments report its domain's.
        foreach (['financial_summary', 'support_cases', 'payments', 'refunds'] as $report) {
            $this->ask($analyst, 'admin', ['report' => $report] + self::PERIOD)->assertStatus(403);
            $this->ask($finance, 'admin', ['report' => $report] + self::PERIOD)->assertStatus(202);
        }
        $this->assertSame(0, DB::table('report_exports')->where('requested_by_admin_id', $analyst->getKey())->count());
        // Only what the endpoint knows may be sent; an unknown report is not a report.
        $this->ask($finance, 'admin', ['report' => 'orders', 'sql' => 'select * from customers'] + self::PERIOD)->assertStatus(422);
        $this->ask($finance, 'admin', ['report' => 'customers'] + self::PERIOD)->assertStatus(422);
        // A market that is not the caller's cannot be exported.
        [$us] = $this->secondMarket();
        $this->ask($this->analyst([], $this->market), 'admin', ['report' => 'orders', 'market' => $us->slug] + self::PERIOD)->assertStatus(403);

        // The permission is taken away after the file was built: the file is no longer theirs to download.
        $id = $this->ask($finance, 'admin', ['report' => 'financial_summary'] + self::PERIOD)->assertStatus(202)->json('export.id');
        $this->fetch($finance, 'admin', '/'.$id.'/download')->assertOk();
        DB::table('role_permissions')->where('permission', Permission::AdminFinanceExport->value)->delete();
        app(AccessControl::class)->flush();
        $this->fetch($finance, 'admin', '/'.$id.'/download')->assertStatus(403);

        // Restaurant side: a viewer may read analytics and may not export; an owner may.
        $staff = $this->member($this->burgerHub->organization, 'VIEWER');
        $this->ask($staff, 'restaurant', ['report' => 'orders'] + self::PERIOD)->assertStatus(403);
        $this->ask($this->member($this->burgerHub->organization, 'MANAGER'), 'restaurant', ['report' => 'menu_items'] + self::PERIOD)->assertStatus(202);
    }

    public function test_a_spreadsheet_cannot_be_made_to_run_what_somebody_typed_into_a_name(): void
    {
        $this->completedOrderWith();
        // A restaurant and a menu item named like formulas.
        DB::table('restaurant_locations')->where('id', $this->burgerHub->getKey())->update(['name' => '=HYPERLINK("http://evil.example","Burger Hub")']);
        DB::table('order_items')->update(['name' => '@SUM(1+1)*cmd|\' /C calc\'!A0']);
        DB::table('payment_attempts')->update(['restaurant_name' => '+cmd|\' /C calc\'!A0']);
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminPaymentsView]);
        $owner = $this->member($this->burgerHub->organization, 'OWNER');

        $performance = $this->fetch($admin, 'admin', '/'.$this->ask($admin, 'admin', ['report' => 'restaurant_performance'] + self::PERIOD)->json('export.id').'/download')->assertOk()->streamedContent();
        $orders = $this->fetch($admin, 'admin', '/'.$this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->json('export.id').'/download')->assertOk()->streamedContent();
        $payments = $this->fetch($admin, 'admin', '/'.$this->ask($admin, 'admin', ['report' => 'payments'] + self::PERIOD)->json('export.id').'/download')->assertOk()->streamedContent();
        $items = $this->fetch($owner, 'restaurant', '/'.$this->ask($owner, 'restaurant', ['report' => 'menu_items'] + self::PERIOD)->json('export.id').'/download')->assertOk()->streamedContent();

        // Every such cell begins with an apostrophe: a spreadsheet shows the text and runs nothing.
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""Burger Hub"")"', $performance);
        $this->assertStringContainsString('"\'=HYPERLINK(', $orders);
        $this->assertStringContainsString('"\'+cmd|', $payments);
        $this->assertStringContainsString('"\'@SUM(1+1)', $items);
        foreach ([$performance, $orders, $payments, $items] as $csv) {
            foreach (explode("\r\n", trim($csv)) as $line) {
                foreach (str_getcsv($line) as $cell) {
                    $this->assertDoesNotMatchRegularExpression('/\A[=+@]/', (string) $cell, $cell);
                    // A cell that begins with a minus is a number and nothing else.
                    if (str_starts_with((string) $cell, '-')) {
                        $this->assertMatchesRegularExpression('/\A-\d+(\.\d+)?\z/', (string) $cell);
                    }
                }
            }
        }
        // Amounts stay numbers.
        $this->assertStringContainsString('"500.00"', $orders);
    }

    public function test_an_export_expires_its_file_is_deleted_and_too_much_is_refused(): void
    {
        $this->completedOrderWith();
        $this->completedOrderWith();
        $this->aggregate();
        $admin = $this->analyst();
        $id = $this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(202)->json('export.id');
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // A day and a minute later: expired — the answer says so, the download is gone, and so is the file.
        $this->travel(24 * 60 + 1)->minutes();
        $this->assertSame(['EXPIRED', null], array_values(array_intersect_key($this->fetch($admin, 'admin', '/'.$id)->assertOk()->json('export'), array_flip(['status', 'download_path']))));
        $this->fetch($admin, 'admin', '/'.$id.'/download')->assertStatus(410)->assertJsonPath('error.code', 'export_expired');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame('EXPIRED', DB::table('report_exports')->where('public_id', $id)->value('status'));
        // The scheduled clean-up does the same for files nobody came back for.
        $other = $this->ask($admin, 'admin', ['report' => 'orders', 'from' => '2026-10-01', 'to' => '2026-10-08'])->assertStatus(202)->json('export.id');
        $this->travel(25)->hours();
        $this->artisan('analytics:prune')->assertSuccessful();
        $this->assertSame(['EXPIRED', []], [DB::table('report_exports')->where('public_id', $other)->value('status'), Storage::disk('local')->allFiles()]);

        // More rows than an export may hold: it fails with a code and leaves no half file.
        config(['analytics.exports.max_rows' => 1]);
        $big = $this->ask($admin, 'admin', ['report' => 'orders', 'from' => '2026-10-01', 'to' => '2026-10-08'])->assertStatus(202)->json('export');
        $this->assertSame(['FAILED', 'too_many_rows', null], [$big['status'], $big['failure_code'], $big['download_path']]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        // A person cannot pile up exports that are still waiting.
        config(['analytics.exports.max_rows' => 50000, 'analytics.exports.max_pending_per_requester' => 2]);
        Queue::fake();
        $this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(202);
        $this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(202);
        $this->ask($admin, 'admin', ['report' => 'orders'] + self::PERIOD)->assertStatus(409)->assertJsonPath('error.code', 'too_many_exports');
        // A range longer than a year is not exported.
        $this->ask($admin, 'admin', ['report' => 'orders', 'from' => '2024-01-01', 'to' => '2026-10-07'])->assertStatus(422);
    }

    public function test_the_curated_reports_carry_the_figures_of_their_domains_and_no_free_text(): void
    {
        $this->feeRule();
        $journey = $this->planGuest();
        $this->restaurantsOf($journey['id'], $journey['headers'])->assertOk();
        [$order, , $headers] = $this->sessionOrder(true, $journey['id']);
        $case = $this->customerCase($headers, ['subject' => 'SECRET SUBJECT of the customer', 'message' => 'PRIVATE MESSAGE of the customer']);
        $this->aggregate();
        $admin = $this->analyst([Permission::AdminFinanceView, Permission::AdminFinanceExport, Permission::AdminSupportView, Permission::AdminPaymentsView, Permission::AdminRefundsView]);
        $file = fn (string $report): string => $this->fetch($admin, 'admin', '/'.$this->ask($admin, 'admin', ['report' => $report] + self::PERIOD)->assertStatus(202)->json('export.id').'/download')->assertOk()->streamedContent();

        // The funnel: eleven stages, the one session at each.
        $funnel = array_map('str_getcsv', array_slice(explode("\r\n", trim($file('customer_funnel'))), 2));
        $this->assertSame([11, ['1', 'Journey search', 'Behavioural event', '1'], '11'], [count($funnel), array_slice($funnel[0], 0, 4), $funnel[10][0]]);
        // Finance: the commission of the day, from the finance module's own report (10 % of ₹500 = ₹50).
        $this->assertStringContainsString('"2026-10-07","INR","1","500.00","500.00","50.00","450.00"', $file('financial_summary'));
        // Support: the case by its number and its codes — never what the customer wrote.
        $support = $file('support_cases');
        $this->assertStringContainsString('"'.$case['case_number'].'"', $support);
        $this->assertStringNotContainsString('SECRET SUBJECT', $support);
        $this->assertStringNotContainsString('PRIVATE MESSAGE', $support);
        // Routes: totals and bands — no place, no coordinate.
        $routes = $file('route_performance');
        $this->assertStringContainsString('"Market total","All journeys","1","0","1","1"', $routes);
        foreach (['West gate', 'East gate', '28.55', '77.3'] as $private) {
            $this->assertStringNotContainsString($private, $routes);
        }
        $this->assertStringContainsString('"'.$order->order_number.'"', $file('orders'));
        $this->assertStringContainsString('"VERIFIED","UPI","INR"', $file('payments'));
        $this->assertSame(6, ReportExport::query()->where('status', 'READY')->count());
    }
}
