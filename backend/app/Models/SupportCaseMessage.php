<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of the conversation between a requester and support (Module 37). EXTERNAL by nature: this is the only
 * table a customer or a restaurant reads. What administrators write for each other is a SupportCaseInternalNote.
 * The body is untrusted plain text; a message is never edited.
 */
class SupportCaseMessage extends Model
{
    use HasPublicId;

    public const AUTHOR_CUSTOMER = 'CUSTOMER';

    public const AUTHOR_RESTAURANT = 'RESTAURANT';

    public const AUTHOR_SUPPORT = 'SUPPORT';

    public const AUTHOR_SYSTEM = 'SYSTEM';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_admin_id');
    }

    public function authorRestaurantUser(): BelongsTo
    {
        return $this->belongsTo(RestaurantUser::class, 'author_restaurant_user_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
