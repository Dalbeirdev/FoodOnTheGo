<?php

namespace App\Services\Restaurant;

use App\Enums\Permission;
use App\Enums\RejectionCategory;
use App\Enums\RestaurantStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Services\Audit\AuditRecorder;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place the administrative status of a restaurant organization or location changes.
 *
 *  - Organization and location each have their own lifecycle (RestaurantStatus). Approving, rejecting,
 *    suspending or reactivating one never rewrites the other: a suspended organization makes all its
 *    locations unavailable through RestaurantAvailabilityService, and one location can be suspended while
 *    the rest of the organization keeps trading.
 *  - A status only moves along RestaurantStatus::transitions(); anything else is a 409.
 *  - Rejecting needs a category and an explanation the restaurant may read; rejecting, suspending and
 *    closing need a reason. The reason is the internal one (audit trail); `public_reason` is the
 *    restaurant-facing text (status_note). They are stored separately.
 *  - Optimistic concurrency (version), row lock, one audit event, cache invalidation — in one transaction.
 *
 * Authorization is the controller's job: the permission for a move is RestaurantStatus::permissionFor().
 * Restaurant users never reach this service, so a restaurant cannot approve itself.
 */
final class RestaurantLifecycleService
{
    private const ACTIONS = [
        'SUBMITTED' => 'submitted', 'UNDER_REVIEW' => 'review_started', 'APPROVED' => 'approved', 'REJECTED' => 'rejected',
        'SUSPENDED' => 'suspended', 'INACTIVE' => 'deactivated', 'DRAFT' => 'returned_to_draft',
    ];

    public function __construct(private readonly AuditRecorder $audit, private readonly RestaurantCatalog $catalog) {}

    /**
     * @template T of RestaurantOrganization|RestaurantLocation
     *
     * @param  T  $record
     * @param  array{version: int, reason?: string|null, public_reason?: string|null, category?: string|null}  $input
     * @return T
     */
    public function transition(RestaurantOrganization|RestaurantLocation $record, RestaurantStatus $target, array $input, AdminUser $actor): RestaurantOrganization|RestaurantLocation
    {
        $reason = PlainText::clean($input['reason'] ?? null, 'reason', multiline: true);
        $publicReason = PlainText::clean($input['public_reason'] ?? null, 'public_reason', multiline: true);
        $category = isset($input['category']) ? RejectionCategory::from($input['category']) : null;

        return DB::transaction(function () use ($record, $target, $input, $actor, $reason, $publicReason, $category) {
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);
            $from = $locked->status;

            if ($from === $target) {
                return $locked;
            }
            if (! $from->canBecome($target)) {
                throw ApiException::conflict('invalid_status_transition', "Status cannot change from {$from->value} to {$target->value}.", [
                    'from' => $from->value, 'allowed' => RestaurantStatus::transitions()[$from->value],
                ]);
            }
            if ($target->needsReason() && $reason === null) {
                throw ValidationException::withMessages(['reason' => ['A reason is required for this change.']]);
            }
            if ($target === RestaurantStatus::Rejected && ($category === null || $publicReason === null)) {
                throw ValidationException::withMessages([($category === null ? 'category' : 'public_reason') => ['A rejection needs a category and an explanation for the restaurant.']]);
            }

            $locked->forceFill([
                'status' => $target,
                'rejection_category' => $target === RestaurantStatus::Rejected ? $category : null,
                // The restaurant-facing explanation only exists while the status it explains is current.
                'status_note' => $target->needsReason() ? $publicReason : null,
                'version' => (int) $locked->version + 1,
                ...match ($target) {
                    RestaurantStatus::Submitted => ['submitted_at' => now()],
                    RestaurantStatus::Approved => ['approved_at' => $locked->approved_at ?? now(), 'suspended_at' => null],
                    RestaurantStatus::Suspended => ['suspended_at' => now()],
                    default => [],
                },
            ])->save();

            $kind = $locked instanceof RestaurantOrganization ? 'restaurant' : 'restaurant_location';
            $action = $from === RestaurantStatus::Suspended && $target === RestaurantStatus::Approved ? 'reactivated' : self::ACTIONS[$target->value];
            $marketId = $locked instanceof RestaurantOrganization ? (int) $locked->primary_market_id : (int) $locked->market_id;

            $this->audit->record("{$kind}.{$action}", $locked, $actor, array_filter([
                'status' => ['from' => $from->value, 'to' => $target->value],
                'rejection_category' => $category === null ? null : ['from' => null, 'to' => $category->value],
                'status_note' => $publicReason === null ? null : ['from' => null, 'to' => $publicReason],
            ]), $reason, $marketId);
            $this->catalog->flush();

            return $locked;
        });
    }

    /**
     * The moves an administrator holding these permissions may make from the current status.
     *
     * @param  callable(Permission): bool  $can
     * @return list<string>
     */
    public function allowedTransitions(RestaurantStatus $from, callable $can): array
    {
        return array_values(array_filter(
            RestaurantStatus::transitions()[$from->value],
            fn (string $target): bool => $can($from->permissionFor(RestaurantStatus::from($target))),
        ));
    }
}
