<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 40 — indexes found by the performance baseline and the pg_stat_statements profile on the medium dataset
 * (docs/reliability/database-performance.md). Each one serves an ordering or a range the API already uses; no query,
 * transaction, lock or check changes.
 *
 *   orders (location_id, pickup_at, id)   the restaurant order board's default list (one location, ORDER BY pickup_at,
 *                                         id) — was a sequential scan + top-N sort of every order of the location
 *   orders (placed_at DESC, id)           the admin order list's default (ORDER BY placed_at DESC, id ASC, platform-wide)
 *                                         — was a sequential scan + sort of every order
 *   ledger_entries (occurred_at)          the finance overview's period figures platform-wide (no market filter) —
 *                                         was a sequential scan of the whole ledger
 *
 * Built CONCURRENTLY (outside a transaction), so creating them on a large production table does not block writes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'orders_location_id_pickup_at_id_index' => 'orders (location_id, pickup_at, id)',
        'orders_placed_at_desc_id_index' => 'orders (placed_at desc, id)',
        'ledger_entries_occurred_at_index' => 'ledger_entries (occurred_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("create index concurrently if not exists {$name} on {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("drop index concurrently if exists {$name}");
        }
    }
};
