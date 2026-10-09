<?php

namespace App\Console\Commands;

use App\Services\Order\OrderCompletionService;
use App\Services\Order\OrderFulfillmentService;
use Illuminate\Console\Command;

/**
 * The orders safety net (Module 31): fulfilment tries that are due again, and verified payments that nobody turned
 * into an order. Runs every minute; safe to run at any time, as often as wanted.
 */
class RecoverOrders extends Command
{
    protected $signature = 'orders:recover';

    protected $description = 'Retries order creation that is due again and picks up verified payments without an order';

    public function handle(OrderFulfillmentService $fulfillment, OrderCompletionService $completion): int
    {
        $result = $fulfillment->sweep();
        // Module 32: picked-up orders whose completion did not happen (a failed job, no worker).
        $completed = $completion->sweep();
        $this->info(sprintf('orders:recover retried %d, picked up %d, completed %d', $result['retried'], $result['picked_up'], $completed));

        return self::SUCCESS;
    }
}
