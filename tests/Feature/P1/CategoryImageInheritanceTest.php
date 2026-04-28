<?php

use App\Models\Category;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-CAT-IMG',
        'name' => 'Warehouse Category Images',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('uploads multiple category images and lists them', function () {
    Storage::fake('public');

    $category = Category::create([
        'name' => 'Categorias Imagen',
        'slug' => 'categorias-imagen-'.uniqid(),
    ]);

    $uploadResponse = $this->post(
        "/api/v1/categories/{$category->id}/images",
        ['images' => [
            UploadedFile::fake()->image('categoria-1.jpg'),
            UploadedFile::fake()->image('categoria-2.jpg'),
        ]]
    );

    $uploadResponse->assertSuccessful();
    $uploadedImages = collect($uploadResponse->json('data'));
    $uploadedPaths = $uploadedImages->pluck('file_path')->all();

    expect($uploadedImages)->toHaveCount(2)
        ->and($uploadedPaths[0])->toStartWith('categories/')
        ->and($uploadedPaths[1])->toStartWith('categories/')
        ->and($uploadedPaths[0])->not->toBe($uploadedPaths[1]);

    Storage::disk('public')->assertExists($uploadedPaths[0]);
    Storage::disk('public')->assertExists($uploadedPaths[1]);

    $category->refresh();
    expect($category->getRawOriginal('image_url'))->toBe($uploadedPaths[0])
        ->and($category->image_url)->toContain('/storage/'.$uploadedPaths[0]);

    $this->getJson("/api/v1/categories/{$category->id}/images")
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

it('deletes one category image without removing the rest', function () {
    Storage::fake('public');

    $category = Category::create([
        'name' => 'Categorias Eliminar Imagen',
        'slug' => 'categorias-eliminar-imagen-'.uniqid(),
    ]);

    $uploadResponse = $this->post(
        "/api/v1/categories/{$category->id}/images",
        ['images' => [
            UploadedFile::fake()->image('categoria-delete-1.jpg'),
            UploadedFile::fake()->image('categoria-delete-2.jpg'),
        ]]
    )->assertSuccessful();

    $images = collect($uploadResponse->json('data'));
    $firstImage = $images->first();
    $secondImage = $images->skip(1)->first();

    Storage::disk('public')->assertExists($firstImage['file_path']);
    Storage::disk('public')->assertExists($secondImage['file_path']);

    $this->deleteJson("/api/v1/categories/{$category->id}/images/{$firstImage['id']}")
        ->assertSuccessful();

    $category->refresh();

    Storage::disk('public')->assertMissing($firstImage['file_path']);
    Storage::disk('public')->assertExists($secondImage['file_path']);

    expect($category->getRawOriginal('image_url'))->toBe($secondImage['file_path']);

    $imagesAfterDelete = $this->getJson("/api/v1/categories/{$category->id}/images")
        ->assertSuccessful()
        ->json('data');

    expect($imagesAfterDelete)->toHaveCount(1)
        ->and($imagesAfterDelete[0]['id'])->toBe($secondImage['id']);
});

it('returns all inherited category images for main and extra categories in product detail', function () {
    Storage::fake('public');

    $mainCategory = Category::create([
        'name' => 'Categoria Principal',
        'slug' => 'categoria-principal-'.uniqid(),
    ]);

    $extraCategory = Category::create([
        'name' => 'Categoria Extra',
        'slug' => 'categoria-extra-'.uniqid(),
    ]);

    $this->post(
        "/api/v1/categories/{$mainCategory->id}/images",
        ['images' => [
            UploadedFile::fake()->image('main-1.jpg'),
            UploadedFile::fake()->image('main-2.jpg'),
        ]]
    )->assertSuccessful();

    $this->post(
        "/api/v1/categories/{$extraCategory->id}/images",
        ['images' => [UploadedFile::fake()->image('extra-1.jpg')]]
    )->assertSuccessful();

    $productResponse = $this->postJson('/api/v1/products', [
        'category_id' => $mainCategory->id,
        'extra_category_ids' => [$extraCategory->id],
        'status' => 'active',
    ])->assertCreated();

    $productId = $productResponse->json('data.id');

    $response = $this->getJson("/api/v1/products/{$productId}")
        ->assertSuccessful();

    $inheritedImages = collect($response->json('data.inherited_images'));
    $inheritedSourceCategoryIds = $inheritedImages
        ->pluck('source_category_id')
        ->sort()
        ->values()
        ->all();

    expect($inheritedImages)->toHaveCount(3)
        ->and($inheritedSourceCategoryIds)->toBe(collect([$mainCategory->id, $mainCategory->id, $extraCategory->id])->sort()->values()->all())
        ->and($inheritedImages->pluck('url')->every(fn ($url) => str_contains((string) $url, '/storage/categories/')))->toBeTrue()
        ->and(collect($response->json('data.all_images')))->toHaveCount(3)
        ->and(collect($response->json('data.images')))->toHaveCount(3)
        ->and($response->json('data.image_url'))->toContain('/storage/categories/');

    $imagesResponse = $this->getJson("/api/v1/products/{$productId}/images")
        ->assertSuccessful();

    expect(collect($imagesResponse->json('data')))->toHaveCount(3)
        ->and(collect($imagesResponse->json('data'))->pluck('source')->unique()->values()->all())->toBe(['category']);
});
