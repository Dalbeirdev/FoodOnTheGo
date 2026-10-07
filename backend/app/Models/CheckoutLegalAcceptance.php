<?php

namespace App\Models;

use App\Enums\LegalDocumentType;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * What a customer accepted when confirming a checkout (Module 29): the document, its version, its effective date and
 * the approval state its text had at that moment. Not just "accepted = true" — and no IP address or device data.
 */
class CheckoutLegalAcceptance extends Model
{
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['document_type' => LegalDocumentType::class, 'document_effective_date' => 'date', 'accepted_at' => 'datetime'];
    }
}
