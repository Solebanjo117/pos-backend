<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dailySummaryContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-DS-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Daily Summary',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    $brand = Brand::create(['name' => 'Marca DS', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'DS-1',
        'name' => 'Producto Daily Summary',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'DS-1-A',
        'status' => 'active',
        'is_default' => true,
    ]);

    return [$admin, $warehouse, $product, $variant];
}

it('returns real cash sales, cogs and roll-forward inventory in the daily summary', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();

    // Credit purchase of 200 on 2026-05-01 raises inventory + accounts payable.
    $supplier = Supplier::create([
        'name' => 'Proveedor DS',
        'email' => 'proveedor-ds@example.test',
    ]);
    PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => '2026-05-01',
        'subtotal' => 200,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 200,
    ]);

    // A cash sale of 100 paid same day, with COGS 60.
    $order = SalesOrder::create([
        'order_number' => 'SO-DS-001',
        'user_id' => $admin->id,
        'date' => '2026-05-02',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'paid',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 100,
        'unit_cost' => 60,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 100,
        'method' => 'cash',
        'paid_at' => '2026-05-02 10:00:00',
        'created_by' => $admin->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/reports/daily-summary?date_from=2026-05-01&date_to=2026-05-03&warehouse_id='.$warehouse->id)
        ->assertOk();

    $rows = collect($response->json('rows'))->keyBy('date');

    // Inventory roll-forward: +200 from credit purchase on day 1, -60 COGS on day 2.
    expect((float) $rows['2026-05-01']['closing_inventory'])->toBe(200.0)
        ->and((float) $rows['2026-05-01']['accounts_payable'])->toBe(200.0)
        ->and((float) $rows['2026-05-02']['closing_inventory'])->toBe(140.0);

    // Sales recognized as real cash sale + COGS on the sale day.
    expect((float) $rows['2026-05-02']['cash_sales'])->toBe(100.0)
        ->and((float) $rows['2026-05-02']['cogs_cash_sales'])->toBe(60.0)
        ->and((float) $rows['2026-05-02']['net_profit'])->toBe(40.0);

    expect((float) $response->json('totals.cash_sales'))->toBe(100.0)
        ->and((float) $response->json('totals.net_profit'))->toBe(40.0);
});

it('reuses cached snapshots for past days but keeps today fresh', function (): void {
    [, $warehouse] = dailySummaryContext();

    $service = app(FinancialSnapshotService::class);
    $pastDate = now()->subDays(3)->toDateString();

    $first = $service->computeDay($pastDate, $warehouse->id);
    $firstComputedAt = $first->computed_at;

    // Recomputing the same closed past day must reuse the cached snapshot.
    $second = $service->computeDay($pastDate, $warehouse->id);
    expect($second->computed_at?->equalTo($firstComputedAt))->toBeTrue();

    // Today must always be (re)computed.
    $todayFirst = $service->computeDay(now()->toDateString(), $warehouse->id);
    $todaySecond = $service->computeDay(now()->toDateString(), $warehouse->id);
    expect($todaySecond->computed_at)->not->toBeNull()
        ->and($todaySecond->computed_at?->greaterThanOrEqualTo($todayFirst->computed_at))->toBeTrue();
});
