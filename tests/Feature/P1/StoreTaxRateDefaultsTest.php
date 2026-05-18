<?php

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function storeTaxRateUserContext(string $role = 'buyer'): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-TAX-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse Tax Defaults',
    ]);

    $user = User::factory()->create([
        'role' => $role,
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    return [$user, $warehouse];
}

it('defaults supply list item tax rate to store tax rate', function () {
    [$buyer, $warehouse] = storeTaxRateUserContext();
    Setting::set('store_tax_rate', 10, 'number', 'store');

    $product = Product::create([
        'sku_base' => 'SUP-TAX-'.fake()->unique()->randomNumber(4),
        'name' => 'Producto Supply Tax',
        'status' => 'active',
    ]);

    $response = $this->actingAs($buyer)->postJson('/api/v1/supply-list', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity_needed' => 1,
        'unit_cost' => 100,
    ]);

    $response->assertCreated();

    expect((int) $response->json('data.tax_rate'))->toBe(10)
        ->and((float) $response->json('data.tax_amount'))->toBe(10.0)
        ->and((float) $response->json('data.total'))->toBe(110.0);
});

it('preserves explicit zero tax rate on supply list items', function () {
    [$buyer, $warehouse] = storeTaxRateUserContext();
    Setting::set('store_tax_rate', 10, 'number', 'store');

    $product = Product::create([
        'sku_base' => 'SUP-ZERO-'.fake()->unique()->randomNumber(4),
        'name' => 'Producto Supply Exento',
        'status' => 'active',
    ]);

    $response = $this->actingAs($buyer)->postJson('/api/v1/supply-list', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity_needed' => 1,
        'unit_cost' => 100,
        'tax_rate' => 0,
    ]);

    $response->assertCreated();

    expect((int) $response->json('data.tax_rate'))->toBe(0)
        ->and((float) $response->json('data.tax_amount'))->toBe(0.0)
        ->and((float) $response->json('data.total'))->toBe(100.0);
});

it('uses store tax rate for category preview when tax percent is omitted', function () {
    [$manager] = storeTaxRateUserContext('manager');
    Setting::set('store_tax_rate', 10, 'number', 'store');

    $response = $this->actingAs($manager)->postJson('/api/v1/categories/preview-price', [
        'formula' => 'costo + costo * ITBM',
        'costo' => 100,
    ]);

    $response->assertOk();

    expect((float) $response->json('price'))->toBe(110.0);
});
