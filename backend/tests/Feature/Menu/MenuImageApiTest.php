<?php

namespace Tests\Feature\Menu;

use App\Models\AuditEvent;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Item photos (Module 24): checked by content, stored behind the storage abstraction, served through
 * /api/v1/media, owned by the item's restaurant, archived rather than deleted.
 */
class MenuImageApiTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase;

    private RestaurantOrganization $organization;

    private RestaurantLocation $burger;

    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        $this->organization = $this->organization('Riverside');
        $this->burger = $this->hours($this->location($this->organization, 'Burger Hub', ['slug' => 'burger-hub']), [[null, '00:00', '00:00']]);
        $this->item = $this->item($this->category($this->menuOf($this->burger)), 'Classic Burger', 24900);
        $this->actingAsPrincipal($this->member($this->organization, 'OWNER'));
    }

    private function upload(UploadedFile $file, ?string $altText = 'A burger', ?MenuItem $item = null): TestResponse
    {
        return $this->post('/api/v1/restaurant/menu/items/'.($item ?? $this->item)->public_id.'/images', array_filter(['image' => $file, 'alt_text' => $altText]), ['Accept' => 'application/json']);
    }

    public function test_a_photo_is_stored_by_content_served_through_media_and_shown_to_customers(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('burger.jpg', 800, 600))->assertCreated();
        $image = $response->json();
        $this->assertSame(['A burger', 800, 600, 0], [$image['alt_text'], $image['width'], $image['height'], $image['display_order']]);
        $this->assertMatchesRegularExpression('#^http://[^/]+/api/v1/media/menu/[0-9a-f-]{36}/[0-9a-f-]{36}\.jpg$#', $image['url']);

        $row = MenuItemImage::query()->firstOrFail();
        Storage::disk('public')->assertExists($row->path);
        $this->assertSame(['image/jpeg', 'ACTIVE'], [$row->mime_type, $row->status]);
        $this->assertStringNotContainsString($row->path, json_encode($image));
        $this->assertSame('menu_item_image.added', AuditEvent::query()->latest('id')->value('action'));

        $this->get(substr($image['url'], strpos($image['url'], '/api/v1/media/')))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'immutable, max-age=86400, public')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($image['url'], $this->getJson('/api/v1/restaurant/menu/items/'.$this->item->public_id)->json('images.0.url'));
        $this->assertSame($image['url'], $this->getJson('/api/v1/restaurants/burger-hub/menu')->json('categories.0.items.0.images.0.url'));
        $this->assertSame($image['url'], $this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->json('data.images.0.url'));
    }

    public function test_the_media_route_serves_only_the_files_this_application_wrote(): void
    {
        $this->getJson('/api/v1/media/menu/not-a-uuid/file.jpg')->assertNotFound()->assertJsonPath('error.code', 'media_not_found');
        $this->getJson('/api/v1/media/menu/'.str_repeat('0', 8).'-0000-0000-0000-000000000000/'.str_repeat('0', 8).'-0000-0000-0000-000000000000.jpg')->assertNotFound();
        $this->getJson('/api/v1/media/.env')->assertNotFound();
        $this->getJson('/api/v1/media/menu/../../.env')->assertNotFound();
        Storage::disk('public')->put('other/secret.jpg', 'x');
        $this->getJson('/api/v1/media/other/secret.jpg')->assertNotFound();
    }

    public function test_files_that_are_not_images_too_small_too_large_or_misnamed_are_refused(): void
    {
        $fields = fn (UploadedFile $file): array => $this->upload($file)->assertUnprocessable()->json('error.details.fields');

        $this->assertArrayHasKey('image', $fields(UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')));
        $this->assertArrayHasKey('image', $fields(UploadedFile::fake()->createWithContent('photo.jpg', '<html><script>alert(1)</script></html>')), 'the name says jpg, the content does not');
        $this->assertArrayHasKey('image', $fields(UploadedFile::fake()->image('tiny.png', 100, 100)));
        $this->assertArrayHasKey('image', $fields(UploadedFile::fake()->image('wide.png', 4001, 300)));
        $this->assertArrayHasKey('image', $fields(UploadedFile::fake()->image('heavy.jpg', 400, 400)->size(3 * 1024)));
        $this->post('/api/v1/restaurant/menu/items/'.$this->item->public_id.'/images', ['alt_text' => 'No file'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('menu_item_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_item_takes_at_most_the_configured_number_of_photos(): void
    {
        config(['menu.limits.images_per_item' => 2]);
        $this->upload(UploadedFile::fake()->image('a.jpg', 300, 300))->assertCreated();
        $this->upload(UploadedFile::fake()->image('b.webp', 300, 300))->assertCreated()->assertJsonPath('display_order', 1);
        $this->upload(UploadedFile::fake()->image('c.png', 300, 300))->assertUnprocessable()->assertJsonPath('error.details.fields.image.0', 'This item already has the maximum number of images (2).');
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_only_staff_who_manage_the_menu_of_that_restaurant_may_upload_change_or_remove(): void
    {
        $image = $this->upload(UploadedFile::fake()->image('a.jpg', 300, 300))->assertCreated()->json();
        $url = '/api/v1/restaurant/menu/items/'.$this->item->public_id.'/images/'.$image['id'];

        $this->actingAsPrincipal($this->member($this->organization, 'VIEWER'));
        $this->upload(UploadedFile::fake()->image('b.jpg', 300, 300))->assertForbidden();
        $this->patchJson($url, ['alt_text' => 'Changed'])->assertForbidden();
        $this->deleteJson($url)->assertForbidden();

        $other = $this->member($this->organization('Second Kitchen'), 'OWNER');
        $this->actingAsPrincipal($other);
        $this->upload(UploadedFile::fake()->image('b.jpg', 300, 300))->assertNotFound();
        $this->patchJson($url, ['alt_text' => 'Changed'])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();

        // an image id under the wrong item of the same restaurant
        $this->actingAsPrincipal($this->member($this->organization, 'OWNER'));
        $second = $this->item($this->item->category, 'Second');
        $this->patchJson('/api/v1/restaurant/menu/items/'.$second->public_id.'/images/'.$image['id'], ['alt_text' => 'Changed'])->assertNotFound()->assertJsonPath('error.code', 'image_not_found');

        $this->assertSame('A burger', MenuItemImage::query()->firstOrFail()->alt_text);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_alt_text_and_order_can_change_and_removing_archives_the_row_but_keeps_the_file(): void
    {
        $first = $this->upload(UploadedFile::fake()->image('a.jpg', 300, 300))->assertCreated()->json();
        $second = $this->upload(UploadedFile::fake()->image('b.jpg', 300, 300), 'Side view')->assertCreated()->json();
        $url = '/api/v1/restaurant/menu/items/'.$this->item->public_id.'/images/';

        $this->patchJson($url.$second['id'], ['alt_text' => 'Close-up', 'display_order' => 0])->assertOk()->assertJsonPath('alt_text', 'Close-up')->assertJsonPath('display_order', 0);
        $this->patchJson($url.$second['id'], ['alt_text' => '<img src=x onerror=alert(1)>'])->assertUnprocessable();
        $this->patchJson($url.$first['id'], ['display_order' => 1])->assertOk();
        $this->assertSame([$second['id'], $first['id']], array_column($this->getJson('/api/v1/restaurant/menu/items/'.$this->item->public_id)->json('images'), 'id'));

        $this->deleteJson($url.$first['id'])->assertNoContent();
        $this->assertSame([$second['id']], array_column($this->getJson('/api/v1/restaurant/menu/items/'.$this->item->public_id)->json('images'), 'id'));
        $this->assertSame([$second['id']], array_column($this->getJson('/api/v1/restaurants/burger-hub/items/classic-burger')->json('data.images'), 'id'));
        $row = MenuItemImage::query()->where('public_id', $first['id'])->firstOrFail();
        $this->assertSame('ARCHIVED', $row->status);
        Storage::disk('public')->assertExists($row->path);
        $this->deleteJson($url.$first['id'])->assertNotFound();
        $this->assertSame(['menu_item_image.added', 'menu_item_image.added', 'menu_item_image.updated', 'menu_item_image.updated', 'menu_item_image.removed'], AuditEvent::query()->where('action', 'like', 'menu_item_image.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_duplicating_an_item_copies_its_photos_as_independent_files(): void
    {
        $this->upload(UploadedFile::fake()->image('a.jpg', 300, 300))->assertCreated();
        $copy = $this->postJson('/api/v1/restaurant/menu/items/'.$this->item->public_id.'/duplicate')->assertCreated()->json();

        $this->assertCount(1, $copy['images']);
        $paths = MenuItemImage::query()->orderBy('id')->pluck('path')->all();
        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1]);
        Storage::disk('public')->assertExists($paths[1]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }
}
