<?php

namespace App\Services\Restaurant;

use App\Auth\Principal;
use App\Enums\RestaurantImageStatus;
use App\Models\Cuisine;
use App\Models\RestaurantFeature;
use App\Models\RestaurantImage;
use App\Models\RestaurantLocation;
use App\Services\Audit\AuditRecorder;
use App\Support\PhoneNumber;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The part of a location that its own staff may edit: how the restaurant presents itself.
 *
 * Only the fields listed in update() can change here — the approval status, the organization, the market,
 * the coordinates, the currency and the time zone are not profile fields and cannot be reached through this
 * service, whatever a request contains (administrators change those; RestaurantAdminService).
 *
 * Text is untrusted content: plain text only (PlainText). Cuisines and features are chosen from the active
 * taxonomies by code, never free text. Every change is one audit event with the before / after values.
 */
final class RestaurantProfileService
{
    private const TEXT = ['name' => false, 'branch_label' => false, 'short_description' => false, 'description' => true, 'pickup_instructions' => true];

    public function __construct(private readonly AuditRecorder $audit, private readonly RestaurantCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $input  version and any of: name, branch_label, short_description, description,
     *                                       pickup_instructions, phone, public_email, website, price_level, cuisines, features
     */
    public function update(RestaurantLocation $location, array $input, Principal $actor): RestaurantLocation
    {
        $location->loadMissing('market');

        return DB::transaction(function () use ($location, $input, $actor): RestaurantLocation {
            $locked = RestaurantLocation::query()->with(['cuisines', 'features'])->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);

            foreach (self::TEXT as $field => $multiline) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, PlainText::clean($input[$field] === null ? null : (string) $input[$field], $field, $multiline));
                }
            }
            if ($locked->name === null) {
                throw ValidationException::withMessages(['name' => ['The restaurant needs a name.']]);
            }
            if (array_key_exists('phone', $input)) {
                $locked->setAttribute('phone_e164', $this->phone($location, $input['phone']));
            }
            if (array_key_exists('public_email', $input)) {
                $locked->setAttribute('public_email', $input['public_email'] === null ? null : mb_strtolower(trim((string) $input['public_email'])));
            }
            foreach (['website', 'price_level'] as $field) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, $input[$field]);
                }
            }

            $changes = [];
            foreach (array_keys($locked->getDirty()) as $key) {
                $changes[$key] = ['from' => $locked->getOriginal($key), 'to' => $locked->getAttribute($key)];
            }

            if (array_key_exists('cuisines', $input)) {
                $cuisines = $this->taxonomy(Cuisine::class, (array) $input['cuisines'], 'cuisines');
                $before = $locked->cuisines->pluck('code')->all();
                if ($before !== array_keys($cuisines)) {
                    $sync = [];
                    foreach (array_values($cuisines) as $position => $id) {
                        $sync[$id] = ['position' => $position];
                    }
                    $locked->cuisines()->sync($sync);
                    $changes['cuisines'] = ['from' => $before, 'to' => array_keys($cuisines)];
                }
            }
            if (array_key_exists('features', $input)) {
                $features = $this->taxonomy(RestaurantFeature::class, (array) $input['features'], 'features');
                $before = $locked->features->pluck('code')->sort()->values()->all();
                $after = collect(array_keys($features))->sort()->values()->all();
                if ($before !== $after) {
                    $locked->features()->sync(array_values($features));
                    $changes['features'] = ['from' => $before, 'to' => $after];
                }
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->setAttribute('version', (int) $locked->version + 1);
            $locked->save();
            $this->audit->record('restaurant_location.profile_changed', $locked, $actor, $changes, null, (int) $locked->market_id);
            $this->catalog->flush();

            return $locked;
        });
    }

    /**
     * Ordering and alternative text of an image that belongs to this location.
     *
     * @param  array{alt_text?: string|null, display_order?: int}  $input
     */
    public function updateImage(RestaurantLocation $location, RestaurantImage $image, array $input, Principal $actor): RestaurantImage
    {
        if (array_key_exists('alt_text', $input)) {
            $image->alt_text = PlainText::clean($input['alt_text'], 'alt_text');
        }
        if (array_key_exists('display_order', $input)) {
            $image->display_order = (int) $input['display_order'];
        }

        if ($image->isDirty()) {
            $changes = [];
            foreach (array_keys($image->getDirty()) as $key) {
                $changes['image.'.$key] = ['from' => $image->getOriginal($key), 'to' => $image->getAttribute($key)];
            }
            $image->save();
            $this->audit->record('restaurant_location.image_changed', $location, $actor, $changes, null, (int) $location->market_id);
            $this->catalog->flush();
        }

        return $image;
    }

    /**
     * Images are never deleted: an archived image is no longer shown and stays for history.
     */
    public function archiveImage(RestaurantLocation $location, RestaurantImage $image, Principal $actor): void
    {
        if ($image->status === RestaurantImageStatus::Archived) {
            return;
        }

        $image->forceFill(['status' => RestaurantImageStatus::Archived])->save();
        $this->audit->record('restaurant_location.image_archived', $location, $actor, ['image' => ['from' => $image->type->value.' '.$image->public_id, 'to' => null]], null, (int) $location->market_id);
        $this->catalog->flush();
    }

    /**
     * Codes → ids of ACTIVE taxonomy entries, in the order given. Unknown or inactive codes are refused.
     *
     * @param  class-string<Cuisine|RestaurantFeature>  $model
     * @param  list<mixed>  $codes
     * @return array<string, int> code => id
     */
    private function taxonomy(string $model, array $codes, string $field): array
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $limit = (int) config("restaurant.limits.{$field}");

        if (count($codes) > $limit) {
            throw ValidationException::withMessages([$field => ["At most {$limit} can be selected."]]);
        }

        $found = $model::query()->active()->whereIn('code', $codes)->pluck('id', 'code');
        $resolved = [];
        foreach ($codes as $index => $code) {
            if (! isset($found[$code])) {
                throw ValidationException::withMessages(["{$field}.{$index}" => ['This option does not exist or is no longer offered.']]);
            }
            $resolved[$code] = (int) $found[$code];
        }

        return $resolved;
    }

    private function phone(RestaurantLocation $location, mixed $phone): ?string
    {
        if ($phone === null || trim((string) $phone) === '') {
            return null;
        }

        try {
            return PhoneNumber::parseBusiness((string) $phone, $location->market)->e164;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => [$e->getMessage()]]);
        }
    }
}
