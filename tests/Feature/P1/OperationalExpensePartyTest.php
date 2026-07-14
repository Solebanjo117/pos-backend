<?php

use App\Models\Customer;
use App\Models\OperationalExpenseBeneficiary;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-PARTY', 'name' => 'Gastos relacionados']);
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->admin->warehouses()->attach($this->warehouse->id);
    $this->actingAs($this->admin);
});

it('relates expenses to customers suppliers beneficiaries or nobody', function (): void {
    $customer = Customer::create(['code' => 'CLI-P1', 'name' => 'Cliente Uno', 'status' => 'active']);
    $supplier = Supplier::create(['name' => 'Proveedor Uno', 'status' => 'active']);
    $beneficiary = $this->postJson('/api/v1/operational-expense-beneficiaries', [
        'name' => 'Juan Salario',
        'type' => 'individual',
    ])->assertCreated()->json('data');

    foreach ([
        ['party_type' => 'customer', 'party_id' => $customer->id, 'amount' => 10],
        ['party_type' => 'supplier', 'party_id' => $supplier->id, 'amount' => 20],
        ['party_type' => 'beneficiary', 'party_id' => $beneficiary['id'], 'amount' => 30],
        ['party_type' => null, 'party_id' => null, 'amount' => 40],
    ] as $row) {
        $this->postJson('/api/v1/operational-expenses', [
            'expense_date' => '2026-07-12',
            'category' => 'payroll',
            'amount' => $row['amount'],
            'payment_method' => 'transfer',
            'warehouse_id' => $this->warehouse->id,
            'party_type' => $row['party_type'],
            'party_id' => $row['party_id'],
        ])->assertCreated();
    }

    $this->getJson('/api/v1/operational-expenses?category=payroll&per_page=100')
        ->assertOk()
        ->assertJsonPath('summary.total_amount', 100)
        ->assertJsonCount(4, 'summary.by_party')
        ->assertJsonFragment(['party_name' => 'Juan Salario', 'total_amount' => 30]);

    $this->getJson('/api/v1/operational-expenses?party_type=beneficiary&party_id='.$beneficiary['id'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.party.name', 'Juan Salario');
});

it('searches every party type and prevents normalized duplicate beneficiaries', function (): void {
    Customer::create(['code' => 'CLI-P2', 'name' => 'Juan Cliente', 'status' => 'active']);
    Supplier::create(['name' => 'Juan Proveedor', 'status' => 'active']);
    OperationalExpenseBeneficiary::create(['name' => 'Juan Beneficiario', 'type' => 'individual']);

    $this->getJson('/api/v1/operational-expenses/parties?q=Juan')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->postJson('/api/v1/operational-expense-beneficiaries', [
        'name' => '  JUAN   BENEFICIARIO ',
        'type' => 'individual',
    ])->assertUnprocessable();
});

it('rejects unavailable parties and multiple legacy party relations', function (): void {
    $customer = Customer::create(['code' => 'CLI-P3', 'name' => 'Cliente Dos', 'status' => 'active']);
    $supplier = Supplier::create(['name' => 'Proveedor Dos', 'status' => 'active']);

    $this->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-07-12',
        'category' => 'payroll',
        'amount' => 25,
        'payment_method' => 'transfer',
        'customer_id' => $customer->id,
        'supplier_id' => $supplier->id,
    ])->assertUnprocessable();
});

it('links active staff through a reusable beneficiary and exposes its expense history', function (): void {
    $seller = User::factory()->create([
        'name' => 'Juan Vendedor',
        'role' => 'seller',
        'is_active' => true,
        'current_warehouse_id' => $this->warehouse->id,
    ]);

    $this->getJson('/api/v1/operational-expenses/parties?q=Juan%20Vendedor')
        ->assertOk()
        ->assertJsonFragment([
            'type' => 'staff',
            'id' => $seller->id,
            'name' => 'Juan Vendedor',
        ]);

    $response = $this->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-07-13',
        'category' => 'payroll',
        'amount' => 75,
        'status' => 'pending',
        'warehouse_id' => $this->warehouse->id,
        'party_type' => 'staff',
        'party_id' => $seller->id,
    ])->assertCreated()
        ->assertJsonPath('data.party.type', 'staff')
        ->assertJsonPath('data.party.id', $seller->id)
        ->assertJsonPath('data.party.name', 'Juan Vendedor');

    $beneficiaryId = $response->json('data.beneficiary_id');
    $this->assertDatabaseHas('operational_expense_beneficiaries', [
        'id' => $beneficiaryId,
        'user_id' => $seller->id,
        'beneficiary_kind' => 'employee',
    ]);

    $this->getJson('/api/v1/operational-expenses?party_type=staff&party_id='.$seller->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.party.id', $seller->id)
        ->assertJsonFragment(['party_name' => 'Juan Vendedor', 'total_amount' => 75]);

    $this->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-07-13',
        'category' => 'payroll',
        'amount' => 25,
        'status' => 'pending',
        'warehouse_id' => $this->warehouse->id,
        'party_type' => 'staff',
        'party_id' => $seller->id,
    ])->assertCreated();

    expect(OperationalExpenseBeneficiary::query()->where('user_id', $seller->id)->count())->toBe(1);
});
