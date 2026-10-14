<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Privacy\CustomerErasureService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Module 39: erases (anonymises in place) a customer who asked for the account to be deleted. A dry run unless --execute
 * is given; refused while anything is still in progress. Prints counts only — never a name, a number or an address.
 */
class PrivacyEraseCustomer extends Command
{
    protected $signature = 'privacy:erase-customer {customer : the customer\'s public id} {--execute : perform the erasure (without it: what would change)} {--reference= : the ticket or request this erasure answers}';

    protected $description = 'Anonymise a customer who asked for deletion (financial records are kept with the contact masked)';

    public function handle(CustomerErasureService $erasure): int
    {
        $id = (string) $this->argument('customer');
        $customer = Str::isUuid($id) ? Customer::query()->where('public_id', $id)->first() : null;
        if ($customer === null) {
            $this->error('This customer does not exist.');

            return self::FAILURE;
        }
        $blockers = $erasure->blockers($customer);
        if ($blockers !== []) {
            $this->line((string) json_encode(['erased' => false, 'blockers' => $blockers]));

            return self::FAILURE;
        }
        if (! $this->option('execute')) {
            $this->line((string) json_encode(['erased' => false, 'dry_run' => true, 'would_change' => $erasure->plan($customer)]));

            return self::SUCCESS;
        }
        $reference = trim((string) $this->option('reference'));
        if ($reference === '') {
            $this->error('An erasure needs --reference (the request or ticket it answers).');

            return self::FAILURE;
        }
        $this->line((string) json_encode(['erased' => true, 'changed' => $erasure->erase($customer, mb_substr($reference, 0, 80))]));

        return self::SUCCESS;
    }
}
