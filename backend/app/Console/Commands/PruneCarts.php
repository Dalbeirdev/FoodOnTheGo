<?php

namespace App\Console\Commands;

use App\Services\Cart\CartService;
use Illuminate\Console\Command;

/**
 * Carts do not live forever (Module 27): a cart nobody touched for its lifetime plus the grace period is closed
 * as EXPIRED, and closed carts older than the retention period are deleted with their lines. A CONVERTED cart —
 * the record an order was created from — is never removed here. Scheduled hourly; safe to run any time.
 */
class PruneCarts extends Command
{
    protected $signature = 'carts:prune';

    protected $description = 'Close expired carts and delete closed carts past their retention period';

    public function handle(CartService $carts): int
    {
        $result = $carts->prune();
        $this->info("Closed {$result['expired']} expired cart(s); deleted {$result['deleted']} closed cart(s) past retention.");

        return self::SUCCESS;
    }
}
