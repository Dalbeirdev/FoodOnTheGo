<?php

namespace App\Services\Menu;

use App\Auth\Principal;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Services\Audit\AuditRecorder;
use App\Support\PlainText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos of menu items. The file is checked by content (MIME from the bytes, real pixel dimensions, size) — never
 * trusted by its name — and stored on the configured disk under menu/{menu}/{uuid}.{ext}; the stored path never
 * appears in a response (GET /api/v1/media/{path} serves it). Removing an image archives the row and keeps the
 * file: a past order may still show it (Module 31).
 */
final class MenuImageService
{
    private const EXTENSION = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly AuditRecorder $audit, private readonly MenuCatalog $catalog) {}

    public function add(MenuItem $item, UploadedFile $file, ?string $altText, Principal $actor): MenuItemImage
    {
        $item->loadMissing('menu.location');
        $config = config('menu.images');

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['image' => ['The upload did not complete. Please try again.']]);
        }
        if ($file->getSize() > (int) $config['max_bytes']) {
            throw ValidationException::withMessages(['image' => ['The image is larger than the allowed size.']]);
        }
        $mime = (string) $file->getMimeType(); // from the bytes, not the file name
        if (! in_array($mime, $config['mime_types'], true)) {
            throw ValidationException::withMessages(['image' => ['Only JPEG, PNG and WebP images are accepted.']]);
        }
        $size = @getimagesize($file->getRealPath());
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw ValidationException::withMessages(['image' => ['The file is not a readable image.']]);
        }
        [$width, $height] = $size;
        if ($width < (int) $config['min_dimension'] || $height < (int) $config['min_dimension']) {
            throw ValidationException::withMessages(['image' => ['The image is too small (at least '.$config['min_dimension'].' × '.$config['min_dimension'].' pixels).']]);
        }
        if ($width > (int) $config['max_dimension'] || $height > (int) $config['max_dimension']) {
            throw ValidationException::withMessages(['image' => ['The image is too large (at most '.$config['max_dimension'].' × '.$config['max_dimension'].' pixels).']]);
        }

        return DB::transaction(function () use ($item, $file, $altText, $actor, $mime, $width, $height, $config): MenuItemImage {
            MenuItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail(); // uploads to one item run one after the other
            $count = MenuItemImage::query()->where('item_id', $item->getKey())->where('status', 'ACTIVE')->count();
            if ($count >= (int) config('menu.limits.images_per_item')) {
                throw ValidationException::withMessages(['image' => ['This item already has the maximum number of images ('.config('menu.limits.images_per_item').').']]);
            }
            $directory = 'menu/'.$item->menu->public_id;
            $name = (string) Str::uuid().'.'.self::EXTENSION[$mime];
            $stored = Storage::disk($config['disk'])->putFileAs($directory, $file, $name);
            if ($stored === false) {
                throw ValidationException::withMessages(['image' => ['The image could not be stored. Please try again.']]);
            }

            $image = (new MenuItemImage)->forceFill([
                'item_id' => $item->getKey(), 'path' => $directory.'/'.$name, 'mime_type' => $mime, 'width' => $width, 'height' => $height,
                'size_bytes' => (int) $file->getSize(), 'alt_text' => PlainText::clean($altText, 'alt_text'), 'status' => 'ACTIVE',
                'display_order' => (int) (MenuItemImage::query()->where('item_id', $item->getKey())->max('display_order') ?? -1) + 1,
            ]);
            $image->save();
            $this->audit->record('menu_item_image.added', $item, $actor, ['image' => ['from' => null, 'to' => $image->public_id], 'mime_type' => ['from' => null, 'to' => $mime]], null, (int) $item->menu->location->market_id);
            $this->catalog->bump($item->menu);

            return $image;
        });
    }

    /**
     * @param  array{alt_text?: string|null, display_order?: int}  $input
     */
    public function update(MenuItemImage $image, array $input, Principal $actor): MenuItemImage
    {
        $image->loadMissing('item.menu.location');

        return DB::transaction(function () use ($image, $input, $actor): MenuItemImage {
            if (array_key_exists('alt_text', $input)) {
                $image->alt_text = PlainText::clean($input['alt_text'], 'alt_text');
            }
            if (array_key_exists('display_order', $input)) {
                $image->display_order = (int) $input['display_order'];
            }
            $changes = [];
            foreach (array_keys($image->getDirty()) as $key) {
                $changes[$key] = ['from' => $image->getOriginal($key), 'to' => $image->getAttribute($key)];
            }
            $image->save();
            if ($changes !== []) {
                $this->audit->record('menu_item_image.updated', $image->item, $actor, $changes + ['image' => ['from' => $image->public_id, 'to' => $image->public_id]], null, (int) $image->item->menu->location->market_id);
                $this->catalog->bump($image->item->menu);
            }

            return $image;
        });
    }

    public function remove(MenuItemImage $image, Principal $actor): void
    {
        $image->loadMissing('item.menu.location');
        DB::transaction(function () use ($image, $actor): void {
            if ($image->status === 'ARCHIVED') {
                return;
            }
            $image->forceFill(['status' => 'ARCHIVED'])->save();
            $this->audit->record('menu_item_image.removed', $image->item, $actor, ['image' => ['from' => $image->public_id, 'to' => null]], null, (int) $image->item->menu->location->market_id);
            $this->catalog->bump($image->item->menu);
        });
    }

    /**
     * Independent copies of the active images of one item for another (used when an item is duplicated).
     */
    public function copy(MenuItem $from, MenuItem $to): void
    {
        $disk = Storage::disk(config('menu.images.disk'));
        foreach ($from->images->where('status', 'ACTIVE') as $image) {
            /** @var MenuItemImage $image */
            $extension = pathinfo((string) $image->path, PATHINFO_EXTENSION);
            $path = dirname((string) $image->path).'/'.Str::uuid().'.'.$extension;
            if ($disk->exists($image->path)) {
                $disk->copy($image->path, $path);
            }
            (new MenuItemImage)->forceFill(['item_id' => $to->getKey(), 'path' => $path, 'mime_type' => $image->mime_type, 'width' => $image->width, 'height' => $image->height, 'size_bytes' => $image->size_bytes, 'alt_text' => $image->alt_text, 'status' => 'ACTIVE', 'display_order' => $image->display_order])->save();
        }
    }
}
