<?php

namespace App\Models;

use App\Enums\SupportLinkType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A typed reference from a case to a record it is about (Module 37). It holds the record's key and the name it had
 * when it was linked — never its state, which is read from the record itself every time.
 */
class SupportCaseLink extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RestaurantSettlement::class, 'settlement_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(RestaurantPayout::class, 'payout_id');
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(PaymentDispute::class, 'dispute_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(RestaurantReview::class, 'review_id');
    }

    public function difference(): BelongsTo
    {
        return $this->belongsTo(ReconciliationDifference::class, 'difference_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(RestaurantLocation::class, 'location_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrganization::class, 'organization_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The internal key of the linked record. */
    public function entityKey(): int
    {
        return (int) $this->getAttribute($this->link_type->column());
    }

    protected function casts(): array
    {
        return ['link_type' => SupportLinkType::class, 'created_at' => 'datetime'];
    }
}
