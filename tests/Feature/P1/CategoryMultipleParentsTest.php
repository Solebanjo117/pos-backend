<?php

use App\Imports\CategoryImport;
use App\Models\Category;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-CAT-PARENTS',
        'name' => 'Warehouse Category Parents',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
});

it('creates categories with multiple real parents while keeping legacy parent id', function () {
    $filters = Category::create([
        'name' => 'Filtros',
        'slug' => 'filtros-'.uniqid(),
        'priority' => 1,
    ]);
    $fuelSystem = Category::create([
        'name' => 'Sistema Combustible',
        'slug' => 'sistema-combustible-'.uniqid(),
        'priority' => 2,
    ]);

    $response = $this->postJson('/api/v1/categories', [
        'name' => 'Filtro combustible',
        'slug' => 'filtro-combustible-'.uniqid(),
        'parent_ids' => [$filters->id, $fuelSystem->id],
        'priority' => 3,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('parent_id', $filters->id)
        ->assertJsonPath('parent_ids.0', $filters->id)
        ->assertJsonPath('parent_ids.1', $fuelSystem->id);

    $categoryId = $response->json('id');

    $this->assertDatabaseHas('category_parents', [
        'category_id' => $categoryId,
        'parent_id' => $filters->id,
        'priority' => 0,
    ]);
    $this->assertDatabaseHas('category_parents', [
        'category_id' => $categoryId,
        'parent_id' => $fuelSystem->id,
        'priority' => 1,
    ]);
    $this->assertDatabaseHas('categories', [
        'id' => $categoryId,
        'parent_id' => $filters->id,
    ]);
});

it('updates parent ids and keeps the legacy parent id synchronized', function () {
    $filters = Category::create(['name' => 'Filtros', 'slug' => 'filtros-'.uniqid()]);
    $fuelSystem = Category::create(['name' => 'Sistema Combustible', 'slug' => 'sistema-combustible-'.uniqid()]);
    $electrical = Category::create(['name' => 'Sistema Electrico', 'slug' => 'sistema-electrico-'.uniqid()]);
    $category = Category::create([
        'name' => 'Filtro combustible',
        'slug' => 'filtro-combustible-'.uniqid(),
        'parent_id' => $filters->id,
    ]);
    $category->syncParents([$filters->id, $fuelSystem->id]);

    $this->putJson("/api/v1/categories/{$category->id}", [
        'parent_ids' => [$fuelSystem->id, $electrical->id],
    ])
        ->assertOk()
        ->assertJsonPath('parent_id', $fuelSystem->id)
        ->assertJsonPath('parent_ids.0', $fuelSystem->id)
        ->assertJsonPath('parent_ids.1', $electrical->id);

    $this->assertDatabaseMissing('category_parents', [
        'category_id' => $category->id,
        'parent_id' => $filters->id,
    ]);
    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'parent_id' => $fuelSystem->id,
    ]);
});

it('shows a category under each real parent in tree responses', function () {
    $filters = Category::create(['name' => 'Filtros', 'slug' => 'filtros-'.uniqid(), 'priority' => 1]);
    $fuelSystem = Category::create(['name' => 'Sistema Combustible', 'slug' => 'sistema-combustible-'.uniqid(), 'priority' => 2]);
    $category = Category::create([
        'name' => 'Filtro combustible',
        'slug' => 'filtro-combustible-'.uniqid(),
        'parent_id' => $filters->id,
        'priority' => 3,
    ]);
    $category->syncParents([$filters->id, $fuelSystem->id]);

    $response = $this->getJson('/api/v1/categories?tree=true')
        ->assertOk();

    $filtersChildren = collect($response->json())
        ->firstWhere('id', $filters->id)['children'] ?? [];
    $fuelSystemChildren = collect($response->json())
        ->firstWhere('id', $fuelSystem->id)['children'] ?? [];

    expect(collect($filtersChildren)->pluck('id')->all())->toContain($category->id)
        ->and(collect($fuelSystemChildren)->pluck('id')->all())->toContain($category->id);
});

it('rejects self parents and parent cycles', function () {
    $root = Category::create(['name' => 'Root', 'slug' => 'root-'.uniqid()]);
    $child = Category::create([
        'name' => 'Child',
        'slug' => 'child-'.uniqid(),
        'parent_id' => $root->id,
    ]);
    $child->syncParents([$root->id]);

    $this->putJson("/api/v1/categories/{$child->id}", [
        'parent_ids' => [$child->id],
    ])->assertStatus(422);

    $this->putJson("/api/v1/categories/{$root->id}", [
        'parent_ids' => [$child->id],
    ])->assertStatus(422);
});

it('keeps legacy parent id requests working', function () {
    $parent = Category::create(['name' => 'Padre Legacy', 'slug' => 'padre-legacy-'.uniqid()]);

    $response = $this->postJson('/api/v1/categories', [
        'name' => 'Hija Legacy',
        'slug' => 'hija-legacy-'.uniqid(),
        'parent_id' => $parent->id,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('parent_id', $parent->id)
        ->assertJsonPath('parent_ids.0', $parent->id);

    $this->assertDatabaseHas('category_parents', [
        'category_id' => $response->json('id'),
        'parent_id' => $parent->id,
    ]);
});

it('imports categories with legacy or multiple parent columns', function () {
    $legacyParent = Category::create(['name' => 'Padre Legacy', 'slug' => 'padre-legacy-'.uniqid()]);
    $filters = Category::create(['name' => 'Filtros', 'slug' => 'filtros-'.uniqid()]);
    $fuelSystem = Category::create(['name' => 'Sistema Combustible', 'slug' => 'sistema-combustible-'.uniqid()]);

    $import = new CategoryImport;
    $legacyCategory = $import->processRow([
        'nombre' => 'Hija import legacy',
        'slug' => 'hija-import-legacy-'.uniqid(),
        'categoria_padre' => $legacyParent->name,
    ]);
    $multipleCategory = $import->processRow([
        'nombre' => 'Filtro combustible importado',
        'slug' => 'filtro-combustible-importado-'.uniqid(),
        'categorias_padre' => "{$filters->name}, {$fuelSystem->name}",
    ]);

    expect($legacyCategory->parent_ids)->toBe([$legacyParent->id])
        ->and($multipleCategory->parent_ids)->toBe([$filters->id, $fuelSystem->id]);

    $this->assertDatabaseHas('categories', [
        'id' => $multipleCategory->id,
        'parent_id' => $filters->id,
    ]);
});
