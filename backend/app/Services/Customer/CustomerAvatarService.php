<?php

namespace App\Services\Customer;

use App\Auth\Principal;
use App\Models\Customer;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The customer's profile photo (Module 25). Checked by content (MIME from the bytes, real pixel dimensions,
 * size), stored on the configured disk under avatars/{customer}/{uuid}.{ext} and served by GET /api/v1/media;
 * a replaced or removed photo is deleted (a profile photo is not a historical record). No path is ever shown.
 */
final class CustomerAvatarService
{
    private const EXTENSION = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function replace(Customer $customer, UploadedFile $file, Principal $actor): Customer
    {
        $config = config('customer.avatar');
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['image' => ['The upload did not complete. Please try again.']]);
        }
        if ($file->getSize() > (int) $config['max_bytes']) {
            throw ValidationException::withMessages(['image' => ['The photo is larger than the allowed size.']]);
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $config['mime_types'], true)) {
            throw ValidationException::withMessages(['image' => ['Only JPEG, PNG and WebP photos are accepted.']]);
        }
        $size = @getimagesize($file->getRealPath());
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw ValidationException::withMessages(['image' => ['The file is not a readable image.']]);
        }
        [$width, $height] = $size;
        if ($width < (int) $config['min_dimension'] || $height < (int) $config['min_dimension']) {
            throw ValidationException::withMessages(['image' => ['The photo is too small (at least '.$config['min_dimension'].' × '.$config['min_dimension'].' pixels).']]);
        }
        if ($width > (int) $config['max_dimension'] || $height > (int) $config['max_dimension']) {
            throw ValidationException::withMessages(['image' => ['The photo is too large (at most '.$config['max_dimension'].' × '.$config['max_dimension'].' pixels).']]);
        }

        $disk = Storage::disk((string) $config['disk']);
        $directory = 'avatars/'.$customer->public_id;
        $name = (string) Str::uuid().'.'.self::EXTENSION[$mime];
        if ($disk->putFileAs($directory, $file, $name) === false) {
            throw ValidationException::withMessages(['image' => ['The photo could not be stored. Please try again.']]);
        }

        return DB::transaction(function () use ($customer, $actor, $disk, $directory, $name, $mime): Customer {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->avatar_path;
            $locked->forceFill(['avatar_path' => $directory.'/'.$name, 'avatar_mime' => $mime, 'avatar_updated_at' => now(), 'version' => (int) $locked->version + 1])->save();
            if ($previous !== null && $previous !== $locked->avatar_path) {
                $disk->delete($previous);
            }
            $this->audit->record('customer.avatar_changed', $locked, $actor, ['avatar' => ['from' => $previous !== null ? 'photo' : null, 'to' => 'photo']], null, $locked->market_id === null ? null : (int) $locked->market_id);

            return $locked->refresh();
        });
    }

    public function remove(Customer $customer, Principal $actor): Customer
    {
        return DB::transaction(function () use ($customer, $actor): Customer {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->avatar_path;
            if ($previous === null) {
                return $locked;
            }
            $locked->forceFill(['avatar_path' => null, 'avatar_mime' => null, 'avatar_updated_at' => now(), 'version' => (int) $locked->version + 1])->save();
            Storage::disk((string) config('customer.avatar.disk'))->delete($previous);
            $this->audit->record('customer.avatar_changed', $locked, $actor, ['avatar' => ['from' => 'photo', 'to' => null]], null, $locked->market_id === null ? null : (int) $locked->market_id);

            return $locked->refresh();
        });
    }

    public static function url(Customer $customer): ?string
    {
        return $customer->avatar_path === null ? null : url('/api/v1/media/'.$customer->avatar_path);
    }
}
