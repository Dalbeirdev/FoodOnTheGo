<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What administrators write for each other on a case (Module 37): findings, provider investigation, finance and
 * security remarks. NEVER part of any answer to a customer or a restaurant — it lives in a table of its own that no
 * requester endpoint reads.
 */
class SupportCaseInternalNote extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_admin_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
