<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision that moves money between a restaurant and the platform (Module 36): who bears a completed refund, who
 * bears a lost dispute, or a manual correction of a restaurant's payable. Each one has a reason, an administrator,
 * an audit event and — once posted — a balanced ledger transaction. It never edits a balance: there is none to edit.
 */
class FinancialAdjustment extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    public const KIND_REFUND = 'REFUND_LIABILITY';

    public const KIND_DISPUTE = 'DISPUTE_LIABILITY';

    public const KIND_MANUAL = 'MANUAL';

    public const STATUS_PENDING_DECISION = 'PENDING_DECISION';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const LIABILITY_UNASSIGNED = 'UNASSIGNED';

    public const LIABILITY_RESTAURANT = 'RESTAURANT';

    public const LIABILITY_PLATFORM = 'PLATFORM';

    public const LIABILITY_SHARED = 'SHARED';

    public const CREDIT_RESTAURANT = 'CREDIT_RESTAURANT';

    public const DEBIT_RESTAURANT = 'DEBIT_RESTAURANT';

    protected $guarded = ['*'];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(PaymentDispute::class, 'dispute_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'restaurant_share_minor' => 'integer', 'platform_share_minor' => 'integer', 'version' => 'integer',
            'decided_at' => 'datetime', 'posted_at' => 'datetime',
        ];
    }
}
