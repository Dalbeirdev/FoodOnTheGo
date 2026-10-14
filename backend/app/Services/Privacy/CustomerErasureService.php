<?php

namespace App\Services\Privacy;

use App\Enums\CustomerStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Services\Analytics\Pseudonymizer;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Module 39 — the erasure FOUNDATION for a customer who asked for the account to be deleted.
 *
 * Erasure is ANONYMISATION IN PLACE, never a row deletion: orders, payments, refunds, the ledger, settlements and audit
 * records must stay (financial / legal retention — durations PENDING LEGAL APPROVAL), and they reference the customer.
 * What identifies the person is removed or masked; the records that belong only to the person are deleted.
 *
 * It runs only for a customer with a deletion request and only when nothing is still in progress (an open order, a
 * refund not final, an open support case): those need the person to be reachable. Run by an operator after the
 * request was reviewed — never automatically (the waiting period and the legal review are business decisions).
 */
final class CustomerErasureService
{
    public const ERASED_NAME = null;

    public function __construct(private readonly Pseudonymizer $pseudonyms, private readonly AuditRecorder $audit) {}

    /**
     * What stands in the way of erasing this customer now (empty = nothing).
     *
     * @return list<string>
     */
    public function blockers(Customer $customer): array
    {
        // An erased account carries the reserved placeholder number: nothing is left to erase (a second run is refused).
        if (str_starts_with((string) $customer->phone_e164, '+999')) {
            return ['already_erased'];
        }
        $blockers = [];
        if ($customer->deletion_requested_at === null) {
            $blockers[] = 'no_deletion_request';
        }
        $openOrders = DB::table('orders')->where('customer_id', $customer->getKey())
            ->whereNotIn('status', array_map(fn (OrderStatus $s): string => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s): bool => $s->isTerminal())))->count();
        if ($openOrders > 0) {
            $blockers[] = "open_orders:{$openOrders}";
        }
        $openRefunds = DB::table('refunds')->where('customer_id', $customer->getKey())->whereNotIn('status', ['REFUNDED', 'FAILED', 'CANCELLED'])->count();
        if ($openRefunds > 0) {
            $blockers[] = "refunds_not_final:{$openRefunds}";
        }
        $openCases = DB::table('support_cases')->where('customer_id', $customer->getKey())->whereNotIn('status', ['RESOLVED', 'CLOSED', 'CANCELLED'])->count();
        if ($openCases > 0) {
            $blockers[] = "open_support_cases:{$openCases}";
        }

        return $blockers;
    }

    /**
     * Counts of what an erasure would change (dry run) — or changed.
     *
     * @return array<string, int>
     */
    public function plan(Customer $customer): array
    {
        $id = $customer->getKey();

        return [
            'orders_contact_masked' => DB::table('orders')->where('customer_id', $id)->count(),
            'access_tokens_deleted' => DB::table('personal_access_tokens')->where('tokenable_type', $customer->getMorphClass())->where('tokenable_id', $id)->count(),
            'devices_revoked' => DB::table('customer_devices')->where('customer_id', $id)->count(),
            'item_instructions_removed' => DB::table('order_items')->whereIn('order_id', DB::table('orders')->select('id')->where('customer_id', $id))->whereNotNull('special_instructions')->count(),
            'saved_places_deleted' => DB::table('customer_saved_locations')->where('customer_id', $id)->count(),
            'recent_places_deleted' => DB::table('customer_recent_locations')->where('customer_id', $id)->count(),
            'favorites_deleted' => DB::table('customer_favorite_locations')->where('customer_id', $id)->count(),
            'payment_method_references_deleted' => DB::table('customer_payment_methods')->where('customer_id', $id)->count(),
            'phone_change_requests_deleted' => DB::table('customer_phone_changes')->where('customer_id', $id)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function erase(Customer $customer, string $operatorReference): array
    {
        $plan = $this->plan($customer);
        $id = $customer->getKey();
        $maskedPhone = $this->mask((string) $customer->phone_e164);
        $avatar = $customer->avatar_path;
        $analyticsKey = $this->pseudonyms->customer((int) $id);

        $statusBefore = $customer->status->value;
        DB::transaction(function () use ($customer, $id, $maskedPhone, $operatorReference, $analyticsKey, $statusBefore): void {
            // Orders stay (financial record); the contact copied into them at the time is masked.
            DB::statement(<<<'SQL'
                update orders set customer_note = null,
                    snapshot = case when snapshot -> 'customer' is not null then jsonb_set(jsonb_set(jsonb_set(snapshot, '{customer,name}', 'null'::jsonb), '{customer,email}', 'null'::jsonb), '{customer,phone}', to_jsonb(?::text)) else snapshot end
                where customer_id = ?
            SQL, [$maskedPhone, $id]);
            // Free text the customer typed on an item ("no onions, call me on …") goes with the contact.
            DB::table('order_items')->whereIn('order_id', DB::table('orders')->select('id')->where('customer_id', $id))->update(['special_instructions' => null]);
            DB::table('personal_access_tokens')->where('tokenable_type', $customer->getMorphClass())->where('tokenable_id', $id)->delete();
            DB::table('customer_devices')->where('customer_id', $id)->update(['revoked_at' => now(), 'revoke_reason' => 'ACCOUNT_CLOSED', 'push_token' => null, 'push_token_hash' => null]);
            foreach (['customer_saved_locations', 'customer_recent_locations', 'customer_favorite_locations', 'customer_payment_methods', 'customer_phone_changes'] as $table) {
                DB::table($table)->where('customer_id', $id)->delete();
            }
            // Behavioural analytics: the pseudonymous key is removed from what the person did.
            foreach (['analytics_events', 'analytics_session_facts'] as $table) {
                DB::table($table)->where('customer_key', $analyticsKey)->update(['customer_key' => null]);
            }
            // The order facts require a key: a random one nobody can derive (still one customer, no longer this person).
            DB::table('analytics_order_facts')->where('customer_key', $analyticsKey)->update(['customer_key' => bin2hex(random_bytes(16))]);
            // The account itself: no name, no e-mail, no birth date…; the number becomes a reserved, non-dialable
            // placeholder (+999 is not assigned to any country) so the row keeps its constraints.
            $customer->forceFill([
                'name' => self::ERASED_NAME, 'email' => null, 'phone_e164' => '+999'.str_pad((string) $id, 11, '0', STR_PAD_LEFT), 'phone_verified_at' => null,
                'date_of_birth' => null, 'gender' => null, 'favorite_cuisines' => null, 'avatar_path' => null, 'avatar_mime' => null, 'avatar_updated_at' => null,
                'deletion_reason' => null, 'promotions_consented_at' => null, 'status' => CustomerStatus::Deactivated, 'version' => (int) $customer->version + 1,
            ])->save();
            $this->audit->record('customer.erased', $customer, null, ['status' => ['from' => $statusBefore, 'to' => 'DEACTIVATED']], 'Erasure after a deletion request ('.$operatorReference.')');
        });
        if (is_string($avatar) && $avatar !== '') {
            Storage::disk((string) config('customer.avatar.disk'))->delete($avatar);
        }

        return $plan;
    }

    private function mask(string $e164): string
    {
        return strlen($e164) < 6 ? '' : substr($e164, 0, 3).str_repeat('*', max(0, strlen($e164) - 7)).substr($e164, -4);
    }
}
