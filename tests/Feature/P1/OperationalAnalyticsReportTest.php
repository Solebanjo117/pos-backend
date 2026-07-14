<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesCreditNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-ANALYTICS', 'name' => 'Reportes operativos']);
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->admin->warehouses()->attach($this->warehouse->id);
    $this->actingAs($this->admin);

    $brand = Brand::create(['name' => 'Marca reportes', 'status' => 'active']);
    $this->product = Product::create([
        'sku_base' => 'REP-001',
        'name' => 'Filtro de aceite',
        'status' => 'active',
    ]);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'REP-001-A',
        'status' => 'active',
    ]);
});

it('reports sales by product net of approved returns with reconciled cost and profit', function (): void {
    $customer = Customer::create(['code' => 'CLI-REP', 'name' => 'Cliente reporte', 'status' => 'active']);
    $order = SalesOrder::create([
        'order_number' => '0000000100',
        'customer_id' => $customer->id,
        'user_id' => $this->admin->id,
        'date' => '2026-07-13',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'paid',
    ]);
    $item = SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'seller_id' => $this->admin->id,
        'quantity' => 2,
        'unit_price' => 50,
        'unit_cost' => 20,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);
    $return = SalesReturn::create([
        'return_number' => '0000000101',
        'sales_order_id' => $order->id,
        'warehouse_id' => $this->warehouse->id,
        'customer_id' => $customer->id,
        'user_id' => $this->admin->id,
        'status' => 'approved',
        'settlement_status' => 'pending',
        'total_amount' => 50,
        'refund_method' => 'cash',
        'approved_by' => $this->admin->id,
        'approved_at' => '2026-07-13 12:00:00',
    ]);
    $return->items()->create([
        'sales_order_item_id' => $item->id,
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'quantity' => 1,
        'unit_price' => 50,
        'subtotal' => 50,
        'user_id' => $this->admin->id,
    ]);

    $this->getJson('/api/v1/reports/sales-by-product?date_from=2026-07-13&date_to=2026-07-13&warehouse_id='.$this->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.0.product_name', 'Filtro de aceite')
        ->assertJsonPath('summary.quantity_sold', 2)
        ->assertJsonPath('summary.quantity_returned', 1)
        ->assertJsonPath('summary.gross_sales', 100)
        ->assertJsonPath('summary.returns_amount', 50)
        ->assertJsonPath('summary.net_sales', 50)
        ->assertJsonPath('summary.cost', 20)
        ->assertJsonPath('summary.profit', 30);
});

it('reports received purchases by supplier and separates paid from payable', function (): void {
    $supplier = Supplier::create(['name' => 'Proveedor reporte', 'status' => 'active']);
    $purchase = PurchaseOrder::create([
        'po_number' => 'PO-0000000100',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $this->warehouse->id,
        'user_id' => $this->admin->id,
        'status' => 'received',
        'payment_status' => 'partial',
        'order_date' => '2026-07-13',
        'subtotal' => 150,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 150,
    ]);
    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchase->id,
        'amount' => 50,
        'method' => 'transfer',
        'paid_at' => '2026-07-13 10:00:00',
        'user_id' => $this->admin->id,
    ]);

    $this->getJson('/api/v1/reports/purchases-by-supplier?date_from=2026-07-13&date_to=2026-07-13&warehouse_id='.$this->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.0.supplier_name', 'Proveedor reporte')
        ->assertJsonPath('summary.orders_count', 1)
        ->assertJsonPath('summary.purchases_total', 150)
        ->assertJsonPath('summary.paid_total', 50)
        ->assertJsonPath('summary.payable_total', 100);
});

it('reports credit notes and valued FIFO inventory from persisted records', function (): void {
    $customer = Customer::create(['code' => 'CLI-NC', 'name' => 'Cliente nota', 'status' => 'active']);
    $order = SalesOrder::create([
        'order_number' => '0000000200',
        'customer_id' => $customer->id,
        'user_id' => $this->admin->id,
        'date' => '2026-07-13',
        'status' => 'completed',
        'subtotal' => 50,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 50,
        'payment_status' => 'paid',
    ]);
    $return = SalesReturn::create([
        'return_number' => '0000000201',
        'sales_order_id' => $order->id,
        'warehouse_id' => $this->warehouse->id,
        'customer_id' => $customer->id,
        'user_id' => $this->admin->id,
        'status' => 'approved',
        'settlement_status' => 'pending',
        'total_amount' => 50,
        'refund_method' => 'cash',
        'approved_by' => $this->admin->id,
        'approved_at' => '2026-07-13 12:00:00',
    ]);
    SalesCreditNote::create([
        'credit_note_number' => '0000000202',
        'sales_return_id' => $return->id,
        'sales_order_id' => $order->id,
        'customer_id' => $customer->id,
        'total_amount' => 50,
        'receivable_amount' => 10,
        'refund_amount' => 30,
        'store_credit_amount' => 10,
        'status' => 'pending',
        'issued_by' => $this->admin->id,
        'issued_at' => '2026-07-13 12:05:00',
    ]);
    InventoryLot::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'source_type' => 'opening_balance',
        'received_quantity' => 3,
        'quantity_available' => 3,
        'unit_cost_base' => 7,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 7,
        'received_at' => '2026-07-13 08:00:00',
    ]);

    $this->getJson('/api/v1/reports/credit-notes?date_from=2026-07-13&date_to=2026-07-13&warehouse_id='.$this->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.0.credit_note_number', '0000000202')
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('summary.total_amount', 50)
        ->assertJsonPath('summary.receivable_amount', 10)
        ->assertJsonPath('summary.refund_amount', 30)
        ->assertJsonPath('summary.store_credit_amount', 10);

    $this->getJson('/api/v1/reports/valued-inventory?date_to=2026-07-13&warehouse_id='.$this->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.0.product_name', 'Filtro de aceite')
        ->assertJsonPath('data.0.quantity_available', 3)
        ->assertJsonPath('data.0.inventory_value', 21)
        ->assertJsonPath('summary.quantity_available', 3)
        ->assertJsonPath('summary.inventory_value', 21);
});
