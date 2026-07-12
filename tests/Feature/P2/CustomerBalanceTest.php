<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function setupCustomerBalanceTest(): array
{
    $warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'TST',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        // Balance behavior is role-agnostic; sellers retain manual sale access,
        // while cashier checkout is covered by CashierCheckoutRestrictionTest.
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'CUST-'.uniqid(),
        'name' => 'Test Customer',
        'credit_limit' => 5000,
        'current_balance' => 0,
        'credit_days' => 30,
    ]);

    $brand = Brand::create(['name' => 'Test Brand', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'TST-001',
        'name' => 'Test Product',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'TST-001-V',
        'status' => 'active',
        'is_default' => true,
    ]);

    ProductPricing::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'base_price' => 100.00,
        'cost' => 50.00,
    ]);

    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 100,
        'quantity_reserved' => 0,
    ]);

    return compact('warehouse', 'user', 'customer', 'product', 'variant');
}

// ─────────────────────────────────────────────────────────────────────────────
// Tests
// ─────────────────────────────────────────────────────────────────────────────

it('venta a credito puro solo genera cargo al balance', function () {
    $data = setupCustomerBalanceTest();
    extract($data);

    $this->actingAs($user)
        ->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'payments' => [],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'tax_percent' => 0,
                ],
            ],
        ])
        ->assertStatus(201);

    $customer->refresh();
    expect((float) $customer->current_balance)->toBe(100.0);

    // Solo debe existir 1 transacción tipo 'sale'
    $transactions = CustomerTransaction::where('customer_id', $customer->id)->get();
    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->type)->toBe('sale');
    expect((float) $transactions->first()->amount)->toBe(100.0);
});

it('venta pagada completamente no genera cargo al balance', function () {
    $data = setupCustomerBalanceTest();
    extract($data);

    $this->actingAs($user)
        ->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'payments' => [
                ['method' => 'cash', 'amount' => 100.00],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'tax_percent' => 0,
                ],
            ],
        ])
        ->assertStatus(201);

    $customer->refresh();
    expect((float) $customer->current_balance)->toBe(0.0);

    $transactions = CustomerTransaction::where('customer_id', $customer->id)->get();
    expect($transactions)->toHaveCount(0);
});

it('venta con pago parcial carga solo el saldo pendiente', function () {
    $data = setupCustomerBalanceTest();
    extract($data);

    $this->actingAs($user)
        ->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'payments' => [
                ['method' => 'cash', 'amount' => 40.00],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'tax_percent' => 0,
                ],
            ],
        ])
        ->assertStatus(201);

    $customer->refresh();
    expect((float) $customer->current_balance)->toBe(60.0);

    $transactions = CustomerTransaction::where('customer_id', $customer->id)
        ->orderBy('created_at')
        ->get();

    expect($transactions)->toHaveCount(1);
    expect($transactions[0]->type)->toBe('sale');
    expect((float) $transactions[0]->amount)->toBe(60.0);
});

it('venta con store_credit no genera deuda incorrecta', function () {
    $data = setupCustomerBalanceTest();
    extract($data);

    // Cliente inicia con saldo a favor de $100 (igual al precio del producto)
    $customer->current_balance = -100.0; // -100 = $100 a favor
    $customer->save();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'payments' => [
                ['method' => 'store_credit', 'amount' => 100.00], // Paga todo con saldo a favor
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 1,
                    'unit_price' => 100.00, // Precio del sistema
                    'tax_percent' => 0,
                ],
            ],
        ]);

    if ($response->status() !== 201) {
        dump($response->json());
    }

    $response->assertStatus(201);

    $customer->refresh();
    // Balance: -100 (store_credit) + 100 (store_credit_used) = 0, confirm loads 0 (full paid)
    // Final: 0 + 0 = 0
    expect((float) $customer->current_balance)->toBe(0.0);

    $transactions = CustomerTransaction::where('customer_id', $customer->id)
        ->orderBy('created_at')
        ->get();

    // Only 1 transaction of type store_credit_used, no sale since fully paid
    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->type)->toBe('store_credit_used');
    expect((float) $transactions->first()->amount)->toBe(100.0);
});
