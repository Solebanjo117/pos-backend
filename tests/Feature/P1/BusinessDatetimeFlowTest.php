<?php

use App\Models\BusinessDatetimeAudit;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\RegionalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function businessDatetimeContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-BDT-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Fecha Operativa',
    ]);
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);
    $seller->warehouses()->attach($warehouse->id);

    $order = SalesOrder::create([
        'order_number' => 'SO-BDT-001',
        'user_id' => $admin->id,
        'date' => '2026-07-01',
        'status' => 'completed',
        'subtotal' => 21.40,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 21.40,
        'payment_status' => 'paid',
    ]);
    $order->forceFill([
        'created_at' => '2026-07-01 10:35:00',
        'updated_at' => '2026-07-01 10:35:00',
    ])->saveQuietly();

    return [$admin, $seller, $warehouse, $order->fresh()];
}

it('audits admin business datetime corrections and preserves created at', function (): void {
    [$admin, $seller, , $order] = businessDatetimeContext();
    $originalCreatedAt = $order->created_at->copy();

    $this->actingAs($admin)
        ->patchJson("/api/v1/business-datetime/sales_order/{$order->id}", [
            'business_datetime' => '2026-07-01 10:20:00',
            'reason' => 'Correccion de hora operativa solicitada por administracion.',
        ])
        ->assertOk()
        ->assertJsonPath('data.record_type', 'sales_order')
        ->assertJsonPath('data.record_id', $order->id)
        ->assertJsonPath('data.latest_audit.changed_by.id', $admin->id);

    $order->refresh();
    expect($order->business_datetime?->format('Y-m-d H:i:s'))->toBe('2026-07-01 10:20:00')
        ->and($order->created_at->equalTo($originalCreatedAt))->toBeTrue();

    $this->assertDatabaseHas('business_datetime_audits', [
        'record_type' => 'sales_order',
        'record_id' => $order->id,
        'reason' => 'Correccion de hora operativa solicitada por administracion.',
        'changed_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/business-datetime/sales_order/{$order->id}", [
            'business_datetime' => '2026-07-01 10:35:00',
            'reason' => 'Restauracion auditada de la hora operativa original.',
        ])
        ->assertOk();

    expect(BusinessDatetimeAudit::query()
        ->where('record_type', 'sales_order')
        ->where('record_id', $order->id)
        ->count())->toBe(2)
        ->and($order->fresh()->created_at->equalTo($originalCreatedAt))->toBeTrue();

    $this->actingAs($seller)
        ->patchJson("/api/v1/business-datetime/sales_order/{$order->id}", [
            'business_datetime' => '2026-07-01 10:10:00',
            'reason' => 'Intento sin permiso administrativo.',
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patchJson("/api/v1/business-datetime/sales_order/{$order->id}", [
            'business_datetime' => '2026-07-01 10:10:00',
            'reason' => 'no',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

it('blocks business datetime changes for sales in a closed cash session', function (): void {
    [$admin, , $warehouse, $order] = businessDatetimeContext();
    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => '2026-07-01 08:00:00',
        'closed_at' => '2026-07-01 18:00:00',
        'opening_amount' => 100,
        'closing_amount' => 100,
        'expected_amount' => 100,
        'status' => 'closed',
    ]);
    $order->update(['pos_session_id' => $session->id]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/business-datetime/sales_order/{$order->id}", [
            'business_datetime' => '2026-07-01 10:20:00',
            'reason' => 'Intento sobre una venta con cierre de caja.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('business_datetime');

    expect(BusinessDatetimeAudit::count())->toBe(0);
});

it('converts a Panama calendar day to inclusive UTC bounds near midnight', function (): void {
    Setting::set('regional_timezone', 'America/Panama', 'string', 'regional');

    [$from, $to] = RegionalDateRange::utcBounds('2026-07-01', '2026-07-01');

    expect($from->format('Y-m-d H:i:s.u'))->toBe('2026-07-01 05:00:00.000000')
        ->and($to->format('Y-m-d H:i:s.u'))->toBe('2026-07-02 04:59:59.999999')
        ->and($from->timezoneName)->toBe('UTC')
        ->and($to->timezoneName)->toBe('UTC');
});
