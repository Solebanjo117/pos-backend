<?php

use App\Models\User;
use App\Models\VehicleChassis;
use App\Models\VehicleMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    $this->toyota = VehicleMake::create(['name' => 'TOYOTA']);
});

function modelPayload(array $overrides = []): array
{
    return [
        'make_id' => test()->toyota->id,
        'name' => 'corolla',
        'year_from' => 1998,
        'year_to' => 1999,
        ...$overrides,
    ];
}

it('stores the model name and a new chassis code in uppercase', function (): void {
    $this->postJson('/api/v1/vehicle-models', modelPayload(['name' => '  corolla   cross ', 'generation' => ' e110 ']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'COROLLA CROSS')
        ->assertJsonPath('data.generation', 'E110');

    expect(VehicleChassis::query()->pluck('code')->all())->toBe(['E110']);
});

it('rejects the same model and chassis in a make regardless of case', function (): void {
    $this->postJson('/api/v1/vehicle-models', modelPayload(['generation' => 'E110']))->assertCreated();

    $this->postJson('/api/v1/vehicle-models', modelPayload(['name' => 'Corolla', 'generation' => 'e110']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'Ya existe el modelo COROLLA E110 en esta marca.']);

    $this->postJson('/api/v1/vehicle-models', modelPayload(['generation' => 'E120', 'year_from' => 2000, 'year_to' => 2007]))
        ->assertCreated();
});

it('uses the chosen chassis code when checking duplicates and on the model', function (): void {
    $first = $this->postJson('/api/v1/vehicle-models', modelPayload(['generation' => 'E110']))->assertCreated();
    $chassisId = VehicleChassis::query()->where('code', 'E110')->value('id');

    $this->postJson('/api/v1/vehicle-models', modelPayload(['chassis_id' => $chassisId]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $sprinter = $this->postJson('/api/v1/vehicle-models', modelPayload(['name' => 'sprinter', 'chassis_id' => $chassisId]))
        ->assertCreated();

    expect($sprinter->json('data.generation'))->toBe('E110')
        ->and(VehicleChassis::count())->toBe(1)
        ->and($first->json('data.id'))->not->toBe($sprinter->json('data.id'));
});

it('reuses a chassis code only within the same make', function (): void {
    $nissan = VehicleMake::create(['name' => 'NISSAN']);
    $this->postJson('/api/v1/vehicle-models', modelPayload(['make_id' => $nissan->id, 'name' => 'kicks', 'generation' => 'P15', 'year_from' => 2016, 'year_to' => 2024]))
        ->assertCreated();

    $this->postJson('/api/v1/vehicle-models', modelPayload(['name' => 'starlet', 'generation' => 'P15', 'year_from' => 2016, 'year_to' => 2024]))
        ->assertCreated();

    $codes = VehicleChassis::query()->with('model.make')->get()
        ->map(fn (VehicleChassis $chassis): string => $chassis->model->make->name.' '.$chassis->code)
        ->sort()->values()->all();

    expect($codes)->toBe(['NISSAN P15', 'TOYOTA P15']);
});

it('rejects renaming a model into an existing one', function (): void {
    $this->postJson('/api/v1/vehicle-models', modelPayload(['generation' => 'E110']))->assertCreated();
    $other = $this->postJson('/api/v1/vehicle-models', modelPayload(['name' => 'sprinter', 'generation' => 'E110']))
        ->assertCreated()->json('data.id');

    $this->putJson("/api/v1/vehicle-models/{$other}", ['name' => 'COROLLA'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('stores makes in uppercase and blocks duplicates regardless of case', function (): void {
    VehicleMake::query()->insert(['name' => 'mazda', 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson('/api/v1/vehicle-makes', ['name' => '  mitsubishi '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'MITSUBISHI');

    $this->postJson('/api/v1/vehicle-makes', ['name' => 'Toyota'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/vehicle-makes', ['name' => 'MAZDA'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});
