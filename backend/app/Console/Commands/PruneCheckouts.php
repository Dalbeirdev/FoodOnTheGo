<?php

namespace App\Console\Commands;

use App\Services\Checkout\CheckoutService;
use Illuminate\Console\Command;

class PruneCheckouts extends Command
{
    protected $signature = 'checkouts:prune';

    protected $description = 'Expire run-out checkouts and pickup holds, release held promotion uses, delete closed checkouts past their retention period';

    public function handle(CheckoutService $checkouts): int
    {
        $result = $checkouts->prune();
        $this->info("Expired {$result['expired']} checkout(s) and {$result['holds_expired']} pickup hold(s); released {$result['promotion_uses_released']} promotion use(s); deleted {$result['deleted']} closed checkout(s) past retention.");

        return self::SUCCESS;
    }
}
