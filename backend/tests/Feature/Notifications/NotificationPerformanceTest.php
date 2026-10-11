<?php

namespace Tests\Feature\Notifications;

use App\Models\CustomerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsNotifications;
use Tests\TestCase;

/**
 * Query counts, not milliseconds: the inbox is a fixed number of queries whatever its length, the unread count is one
 * query, and a ready notice for a customer with three devices costs a bounded number of statements (notification +
 * deliveries + three sends through the development provider, queue sync).
 */
class NotificationPerformanceTest extends TestCase
{
    use BuildsNotifications, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
    }

    public function test_the_inbox_and_the_unread_count_cost_a_fixed_number_of_queries(): void
    {
        [$customer, $headers, $order] = $this->paidOrderWithDevices([]);
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();
        $this->step($order['order_number'], 'ready')->assertOk();
        $this->assertSame(4, CustomerNotification::query()->where('customer_id', $customer->getKey())->count());

        $this->using($headers);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders($headers)->getJson('/api/v1/customer/notifications')->assertOk()->assertJsonCount(4, 'data');
        $four = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->withHeaders($headers)->getJson('/api/v1/customer/notifications/unread-count')->assertOk();
        $unread = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $four, 'token lookup, count, page, unread count — never a per-row query');
        $this->assertLessThanOrEqual(3, $unread);
        file_put_contents(storage_path('logs/m33-performance.json'), json_encode(['inbox_queries_four_rows' => $four, 'unread_count_queries' => $unread], JSON_PRETTY_PRINT));
    }

    public function test_a_ready_notice_for_three_devices_costs_a_bounded_number_of_statements(): void
    {
        [, , $order] = $this->paidOrderWithDevices(['inst-a-00000001' => 'fake-token-a', 'inst-b-00000001' => 'fake-token-b', 'inst-c-00000001' => 'fake-token-c']);
        $this->orderStaff();
        $this->step($order['order_number'], 'accept')->assertOk();
        $this->step($order['order_number'], 'start-preparing')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->step($order['order_number'], 'ready')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The order step itself (Module 31 / 32) is about 30 statements; the notice adds the notification, five delivery rows,
        // three sends (lease, attempt, result each), the SMS send and the metrics — all bounded, none per order line.
        $this->assertLessThanOrEqual(95, $queries);
        $current = is_file(storage_path('logs/m33-performance.json')) ? (array) json_decode((string) file_get_contents(storage_path('logs/m33-performance.json')), true) : [];
        file_put_contents(storage_path('logs/m33-performance.json'), json_encode($current + ['ready_step_with_three_devices_queries' => $queries], JSON_PRETTY_PRINT));
    }
}
