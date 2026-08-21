<?php

namespace Database\Seeders;

use App\Helpers\TaxRateHelper;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\InventoryMovement;
use App\Models\PosSession;
use App\Models\ProductBatch;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\SalesQuote;
use App\Models\SalesQuoteItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseBatch;
use App\Services\InventoryFifoService;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🌱 Creando datos de demostración...');

        // ── 1. USERS ──
        $admin = User::updateOrCreate(
            ['email' => 'admin@hermes.com'],
            ['name' => 'Admin Principal', 'username' => 'admin', 'password' => bcrypt('password'), 'role' => 'admin', 'is_active' => true]
        );
        $cashier = User::updateOrCreate(
            ['email' => 'cajero@hermes.com'],
            ['name' => 'María López', 'username' => 'cashier', 'password' => bcrypt('password'), 'role' => 'cashier', 'is_active' => true]
        );
        $seller = User::updateOrCreate(
            ['email' => 'vendedor@hermes.com'],
            ['name' => 'Carlos Pérez', 'username' => 'seller', 'password' => bcrypt('password'), 'role' => 'seller', 'is_active' => true]
        );
        $this->command->info('✅ 3 usuarios creados/actualizados (admin, cajero, vendedor)');

        // ── 2. CUSTOMERS ──
        $customers = [];
        $customerData = [
            ['name' => 'Taller AutoMax', 'type' => 'business', 'email' => 'compras@automax.com', 'phone' => '6700-1234', 'tax_id' => '155-123-456789', 'credit_limit' => 10000, 'credit_days' => 30, 'city' => 'Panamá'],
            ['name' => 'Juan García', 'type' => 'individual', 'email' => 'juang@gmail.com', 'phone' => '6600-5678', 'credit_limit' => 2000, 'credit_days' => 15, 'city' => 'David'],
            ['name' => 'Repuestos El Chino', 'type' => 'business', 'email' => 'ventas@elchino.com', 'phone' => '6800-9012', 'tax_id' => '155-789-012345', 'credit_limit' => 15000, 'credit_days' => 30, 'city' => 'Colón'],
            ['name' => 'Roberto Méndez', 'type' => 'individual', 'email' => 'roberto.m@hotmail.com', 'phone' => '6500-3456', 'credit_limit' => 0, 'credit_days' => 0, 'city' => 'Chitré'],
            ['name' => 'Mecánica Rápida S.A.', 'type' => 'business', 'email' => 'info@mecanicrapida.com', 'phone' => '6900-7890', 'tax_id' => '155-456-789012', 'credit_limit' => 8000, 'credit_days' => 15, 'city' => 'Santiago'],
            ['name' => 'Ana Castillo', 'type' => 'individual', 'email' => 'anacast@gmail.com', 'phone' => '6400-1122', 'credit_limit' => 3000, 'credit_days' => 15, 'city' => 'Panamá'],
        ];
        foreach ($customerData as $cd) {
            $customers[] = Customer::firstOrCreate(
                ['email' => $cd['email']],
                array_merge($cd, ['code' => Customer::generateCode(), 'status' => 'active', 'country' => 'Panamá', 'current_balance' => 0])
            );
        }
        $this->command->info('✅ 6 clientes creados');

        // ── 3. GET ALL WAREHOUSES & VARIANTS ──
        $warehouses = Warehouse::where('status', 'active')->get();
        if ($warehouses->isEmpty()) {
            $this->command->error('❌ No hay almacenes activos.');

            return;
        }

        $variants = ProductVariant::with(['pricing', 'product'])->whereNull('deleted_at')->get();
        if ($variants->isEmpty()) {
            $this->command->error('❌ No hay variantes de productos.');

            return;
        }

        $suppliers = Supplier::all();

        // Assign warehouse access via pivot table
        // Admin: has access to all by role check, no pivot needed
        // Cashier: assigned to first 2 warehouses
        // Seller: assigned to all warehouses
        $cashier->warehouses()->sync($warehouses->take(2)->pluck('id')->toArray());
        $seller->warehouses()->sync($warehouses->pluck('id')->toArray());

        // Set default warehouse for users (first one as default)
        User::query()->update(['current_warehouse_id' => $warehouses->first()->id]);

        // ── 4. INVENTORY ACROSS ALL WAREHOUSES ──
        $this->command->info('📦 Distribuyendo inventario en '.$warehouses->count().' almacenes...');
        foreach ($warehouses as $warehouse) {
            foreach ($variants as $variant) {
                $qty = rand(20, 150);
                $inventory = ProductInventory::firstOrCreate(
                    ['variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $variant->product_id],
                    ['quantity_available' => $qty, 'quantity_reserved' => 0, 'reorder_point' => 10, 'reorder_quantity' => 30]
                );

                app(InventoryFifoService::class)->ensureOpeningLotForInventory(
                    $variant->product_id,
                    $variant->id,
                    $warehouse->id,
                    (int) $inventory->quantity_available,
                    (float) ($variant->pricing?->cost ?? $variant->pricing?->cost_base ?? 0)
                );
            }
        }
        $this->command->info('✅ Inventario distribuido en todos los almacenes');

        // ── 5. INVENTORY BATCHES (LOTES) ──
        $this->command->info('🏷️ Creando lotes de inventario...');
        $batchCount = 0;
        $batchProducts = $variants->random(min(8, $variants->count()));

        foreach ($batchProducts as $variant) {
            $numBatches = rand(2, 4);
            for ($b = 0; $b < $numBatches; $b++) {
                $mfgDate = now()->subMonths(rand(1, 12));
                $expiryDate = $mfgDate->copy()->addMonths(rand(6, 24));
                $batchNumber = 'LOT-'.strtoupper(substr($variant->sku, 0, 4)).'-'.$mfgDate->format('Ym').'-'.str_pad($b + 1, 2, '0', STR_PAD_LEFT);

                $batch = ProductBatch::firstOrCreate(
                    ['product_id' => $variant->product_id, 'batch_number' => $batchNumber],
                    [
                        'manufacturing_date' => $mfgDate,
                        'expiry_date' => $expiryDate,
                        'cost' => rand(5, 60),
                        'supplier_id' => $suppliers->isNotEmpty() ? $suppliers->random()->id : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Distribute batch across 1-3 warehouses
                $batchWarehouses = $warehouses->random(min(rand(1, 3), $warehouses->count()));
                foreach ($batchWarehouses as $wh) {
                    $qty = rand(5, 40);
                    WarehouseBatch::firstOrCreate(
                        ['warehouse_id' => $wh->id, 'product_batch_id' => $batch->id],
                        [
                            'quantity' => $qty,
                            'quantity_reserved' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
                $batchCount++;
            }
        }
        $this->command->info("✅ {$batchCount} lotes creados para ".$batchProducts->count().' productos');

        // ── 6. POS SESSIONS & SALES ORDERS (últimos 7 días, all warehouses) ──
        $this->command->info('🛒 Creando ventas y sesiones POS...');
        $allOrders = [];
        $sessionUsers = [$cashier, $seller];

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo);

            // Create a session per warehouse per day
            foreach ($warehouses as $whIdx => $warehouse) {
                $sessionUser = $sessionUsers[$whIdx % count($sessionUsers)];

                $session = PosSession::create([
                    'user_id' => $sessionUser->id,
                    'warehouse_id' => $warehouse->id,
                    'opened_at' => $date->copy()->setTime(8, 0),
                    'closed_at' => $date->copy()->setTime(18, 0),
                    'opening_amount' => 200.00,
                    'closing_amount' => 0,
                    'expected_amount' => 0,
                    'status' => 'closed',
                    'notes' => "Sesión {$warehouse->name} - ".$date->format('d/m/Y'),
                ]);

                // 2-4 orders per session
                $ordersPerSession = rand(2, 4);
                $sessionCash = 0;
                $sessionCard = 0;
                $sessionTransfer = 0;

                for ($o = 0; $o < $ordersPerSession; $o++) {
                    $customer = rand(0, 3) > 0 ? $customers[array_rand($customers)] : null;
                    $numItems = rand(1, 3);
                    $usedVariants = $variants->random(min($numItems, $variants->count()));

                    $paymentMethods = ['cash', 'cash', 'card', 'transfer', 'multiple'];
                    if ($customer && $customer->credit_limit > 0 && rand(0, 3) === 0) {
                        $paymentMethods[] = 'credit';
                    }
                    $selectedMethod = $paymentMethods[array_rand($paymentMethods)];

                    $order = SalesOrder::create([
                        'customer_id' => $customer?->id,
                        'user_id' => $sessionUser->id,
                        'pos_session_id' => $session->id,
                        'date' => $date->format('Y-m-d'),
                        'status' => 'completed',
                        'payment_status' => 'paid',
                        'payment_method' => $selectedMethod,
                        'subtotal' => 0,
                        'tax_amount' => 0,
                        'discount_amount' => 0,
                        'shipping_cost' => 0,
                        'total' => 0,
                    ]);

                    foreach ($usedVariants as $variant) {
                        $pricing = $variant->pricing->first();
                        $unitPrice = $pricing ? (float) $pricing->base_price : rand(15, 200);
                        $qty = rand(1, 3);

                        SalesOrderItem::create([
                            'sales_order_id' => $order->id,
                            'product_id' => $variant->product_id,
                            'variant_id' => $variant->id,
                            'warehouse_id' => $warehouse->id,
                            'seller_id' => $sessionUser->id,
                            'quantity' => $qty,
                            'unit_price' => $unitPrice,
                            'unit_cost' => round($unitPrice * 0.6, 2),
                            'discount_percent' => 0,
                            'tax_percent' => rand(0, 1) ? 7 : 0,
                        ]);
                    }

                    $order->refresh();
                    $total = (float) $order->total;
                    $paidAt = $date->copy()->setTime(rand(9, 17), rand(0, 59));

                    if ($selectedMethod === 'multiple') {
                        $cashPart = round($total * 0.4, 2);
                        $cardPart = round($total - $cashPart, 2);
                        SalesOrderPayment::create(['sales_order_id' => $order->id, 'amount' => $cashPart, 'method' => 'cash', 'notes' => 'Pago POS', 'paid_at' => $paidAt, 'created_by' => $sessionUser->id]);
                        SalesOrderPayment::create(['sales_order_id' => $order->id, 'amount' => $cardPart, 'method' => 'card', 'notes' => 'Pago POS [visa]', 'paid_at' => $paidAt, 'created_by' => $sessionUser->id]);
                        $sessionCash += $cashPart;
                        $sessionCard += $cardPart;
                    } elseif ($selectedMethod === 'credit' && $customer) {
                        SalesOrderPayment::create(['sales_order_id' => $order->id, 'amount' => $total, 'method' => 'credit', 'notes' => 'Crédito al cliente', 'paid_at' => $paidAt, 'created_by' => $sessionUser->id]);
                        $balanceBefore = (float) $customer->current_balance;
                        $customer->current_balance = $balanceBefore + $total;
                        $customer->save();
                        CustomerTransaction::create([
                            'customer_id' => $customer->id,
                            'type' => 'sale',
                            'reference_type' => 'sales_order',
                            'reference_id' => $order->id,
                            'amount' => $total,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $customer->current_balance,
                            'description' => "Venta a crédito {$order->order_number}",
                            'transaction_date' => $paidAt,
                            'created_by' => $sessionUser->id,
                        ]);
                    } else {
                        SalesOrderPayment::create(['sales_order_id' => $order->id, 'amount' => $total, 'method' => $selectedMethod, 'notes' => 'Pago POS'.($selectedMethod === 'card' ? ' [visa]' : ''), 'paid_at' => $paidAt, 'created_by' => $sessionUser->id]);
                        if ($selectedMethod === 'cash') {
                            $sessionCash += $total;
                        }
                        if ($selectedMethod === 'card') {
                            $sessionCard += $total;
                        }
                        if ($selectedMethod === 'transfer') {
                            $sessionTransfer += $total;
                        }
                    }

                    foreach ($order->items as $item) {
                        $fifoResult = app(InventoryFifoService::class)->consumeStock([
                            'product_id' => $item->product_id,
                            'variant_id' => $item->variant_id,
                            'warehouse_id' => $warehouse->id,
                            'quantity' => $item->quantity,
                            'reference_type' => 'sale',
                            'reference_id' => $order->id,
                            'sales_order_item_id' => $item->id,
                            'notes' => "Venta {$order->order_number}",
                            'user_id' => $sessionUser->id,
                            'strict' => false,
                        ]);

                        if (($fifoResult['weighted_unit_cost'] ?? 0) > 0) {
                            $item->forceFill(['unit_cost' => $fifoResult['weighted_unit_cost']])->save();
                        }
                    }

                    $allOrders[] = $order;
                }

                // Update POS session totals
                $expectedCash = 200 + $sessionCash;
                $session->update([
                    'expected_amount' => $expectedCash,
                    'closing_amount' => $expectedCash - rand(0, 5),
                    'closing_details' => [
                        'counted_cash' => $expectedCash - rand(0, 5),
                        'electronic_payments' => $sessionCard + $sessionTransfer > 0 ? [
                            ['type' => 'visa', 'amount' => round($sessionCard * 0.7, 2)],
                            ['type' => 'yappy', 'amount' => round($sessionTransfer * 0.5, 2)],
                            ['type' => 'transferencia', 'amount' => round($sessionTransfer * 0.5, 2)],
                        ] : [],
                    ],
                ]);
            }
        }
        $this->command->info('✅ '.count($allOrders).' órdenes en '.($warehouses->count() * 7).' sesiones POS');

        // ── 7. OPEN SESSION TODAY (each warehouse) ──
        foreach ($warehouses as $whIdx => $warehouse) {
            PosSession::create([
                'user_id' => $sessionUsers[$whIdx % count($sessionUsers)]->id,
                'warehouse_id' => $warehouse->id,
                'opened_at' => now()->setTime(8, 0),
                'opening_amount' => 200.00,
                'status' => 'open',
            ]);
        }
        $this->command->info('✅ '.$warehouses->count().' sesiones POS abiertas (hoy)');

        // ── 8. SALES QUOTES ──
        foreach ($warehouses->take(3) as $warehouse) {
            $quoteStatuses = ['draft', 'sent', 'accepted', 'rejected', 'expired'];
            for ($i = 0; $i < 3; $i++) {
                $customer = $customers[array_rand($customers)];
                $quote = SalesQuote::create([
                    'customer_id' => $customer->id,
                    'user_id' => $seller->id,
                    'warehouse_id' => $warehouse->id,
                    'date' => now()->subDays(rand(1, 14)),
                    'valid_until' => now()->addDays(rand(-5, 15)),
                    'status' => $quoteStatuses[array_rand($quoteStatuses)],
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => 0,
                    'notes' => "Cotización - {$warehouse->name}",
                ]);
                foreach ($variants->random(rand(1, 3)) as $variant) {
                    $pricing = $variant->pricing->first();
                    SalesQuoteItem::create([
                        'sales_quote_id' => $quote->id,
                        'product_id' => $variant->product_id,
                        'variant_id' => $variant->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => rand(1, 5),
                        'unit_price' => $pricing ? (float) $pricing->base_price : rand(20, 150),
                        'discount_percent' => rand(0, 1) ? rand(5, 15) : 0,
                        'tax_percent' => rand(0, 1) ? 7 : 0,
                    ]);
                }
            }
        }
        $this->command->info('✅ 9 cotizaciones creadas');

        // ── 9. PURCHASE ORDERS (across warehouses) ──
        if ($suppliers->isNotEmpty()) {
            $poStatuses = ['draft', 'sent', 'partial', 'received', 'received'];
            foreach ($warehouses as $whIdx => $warehouse) {
                for ($i = 0; $i < 2; $i++) {
                    $supplier = $suppliers->random();
                    $poDate = now()->subDays(rand(1, 20));
                    $statusIdx = ($whIdx * 2 + $i) % count($poStatuses);
                    $status = $poStatuses[$statusIdx];

                    $po = PurchaseOrder::create([
                        'supplier_id' => $supplier->id,
                        'warehouse_id' => $warehouse->id,
                        'user_id' => $admin->id,
                        'status' => $status,
                        'payment_status' => $status === 'received' ? 'paid' : ($status === 'partial' ? 'partial' : 'pending'),
                        'order_date' => $poDate,
                        'expected_date' => $poDate->copy()->addDays(rand(3, 14)),
                        'received_date' => in_array($status, ['received', 'partial']) ? $poDate->copy()->addDays(rand(3, 10)) : null,
                        'subtotal' => 0,
                        'tax_amount' => 0,
                        'shipping_cost' => rand(0, 1) ? rand(15, 50) : 0,
                        'total' => 0,
                        'notes' => "OC - {$warehouse->name}",
                    ]);

                    $subtotal = 0;
                    foreach ($variants->random(rand(2, 4)) as $variant) {
                        $cost = rand(10, 80);
                        $qty = rand(5, 30);
                        $qtyReceived = $status === 'received' ? $qty : ($status === 'partial' ? intval($qty * 0.5) : 0);
                        $lineTotal = $cost * $qty;
                        $subtotal += $lineTotal;

                        $poItem = PurchaseOrderItem::create([
                            'purchase_order_id' => $po->id,
                            'product_id' => $variant->product_id,
                            'variant_id' => $variant->id,
                            'quantity_ordered' => $qty,
                            'quantity_received' => $qtyReceived,
                            'unit_cost' => $cost,
                            'subtotal' => $lineTotal,
                        ]);

                        if ($qtyReceived > 0) {
                            app(InventoryFifoService::class)->receiveStock([
                                'product_id' => $variant->product_id,
                                'variant_id' => $variant->id,
                                'warehouse_id' => $warehouse->id,
                                'quantity' => $qtyReceived,
                                'unit_cost_base' => $cost,
                                'landed_unit_cost' => $cost,
                                'reference_type' => 'purchase_order',
                                'reference_id' => $po->id,
                                'source_type' => 'purchase_order',
                                'source_id' => $poItem->id,
                                'notes' => "Recepción OC {$po->po_number}",
                                'user_id' => $admin->id,
                            ]);
                        }
                    }

                    $taxAmount = TaxRateHelper::amount($subtotal);
                    $po->update(['subtotal' => $subtotal, 'tax_amount' => $taxAmount, 'total' => $subtotal + $taxAmount + (float) $po->shipping_cost]);

                    if ($po->payment_status === 'paid') {
                        PurchaseOrderPayment::create(['purchase_order_id' => $po->id, 'amount' => (float) $po->total, 'method' => 'transfer', 'reference_number' => 'TRF-'.rand(10000, 99999), 'notes' => 'Pago completo', 'paid_at' => $po->received_date ?? now(), 'user_id' => $admin->id]);
                    } elseif ($po->payment_status === 'partial') {
                        PurchaseOrderPayment::create(['purchase_order_id' => $po->id, 'amount' => round((float) $po->total * 0.5, 2), 'method' => 'transfer', 'reference_number' => 'TRF-'.rand(10000, 99999), 'notes' => 'Anticipo 50%', 'paid_at' => $po->order_date, 'user_id' => $admin->id]);
                    }
                }
            }
            $this->command->info('✅ '.($warehouses->count() * 2).' órdenes de compra creadas');
        }

        // ── 10. ENSURE CUSTOMER DEBTS ──
        $debtCustomers = [$customers[0], $customers[2], $customers[4]]; // Business customers
        foreach ($debtCustomers as $dc) {
            if ((float) $dc->current_balance <= 0) {
                // Force a credit debt
                $debtAmount = rand(500, 3000);
                $balanceBefore = (float) $dc->current_balance;
                $dc->current_balance = $balanceBefore + $debtAmount;
                $dc->save();
                CustomerTransaction::create([
                    'customer_id' => $dc->id,
                    'type' => 'sale',
                    'reference_type' => 'manual_adjustment',
                    'amount' => $debtAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $dc->current_balance,
                    'description' => 'Saldo pendiente de ventas anteriores',
                    'transaction_date' => now()->subDays(rand(3, 10)),
                    'created_by' => $admin->id,
                ]);
            }
        }

        // Partial payment on first debt customer
        $dc = $debtCustomers[0];
        $partialPayment = round((float) $dc->current_balance * 0.3, 2);
        $balanceBefore = (float) $dc->current_balance;
        $dc->current_balance = $balanceBefore - $partialPayment;
        $dc->save();
        CustomerTransaction::create([
            'customer_id' => $dc->id,
            'type' => 'payment',
            'reference_type' => 'manual_payment',
            'amount' => $partialPayment,
            'balance_before' => $balanceBefore,
            'balance_after' => $dc->current_balance,
            'description' => 'Abono parcial en efectivo',
            'transaction_date' => now()->subDays(1),
            'created_by' => $admin->id,
        ]);
        $this->command->info('✅ 3 clientes con deudas');

        // ── SUMMARY ──
        $this->command->info('');
        $this->command->info('📊 Resumen de datos de demostración:');
        $this->command->info('   Almacenes: '.$warehouses->count());
        $this->command->info('   Usuarios: '.User::count());
        $this->command->info('   Clientes: '.Customer::count());
        $this->command->info('   Órdenes de venta: '.SalesOrder::count());
        $this->command->info('   Pagos registrados: '.SalesOrderPayment::count());
        $this->command->info('   Sesiones POS: '.PosSession::count());
        $this->command->info('   Cotizaciones: '.SalesQuote::count());
        $this->command->info('   Órdenes de compra: '.PurchaseOrder::count());
        $this->command->info('   Lotes de inventario: '.ProductBatch::count());
        $this->command->info('   Lotes en almacenes: '.WarehouseBatch::count());
        $this->command->info('   Movimientos inventario: '.InventoryMovement::count());
        $this->command->info('   Clientes con deuda: '.Customer::where('current_balance', '>', 0)->count());
        $this->command->info('');
        $this->command->info('🎉 ¡Datos de demostración creados exitosamente!');
    }
}
