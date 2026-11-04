<?php

use App\Imports\VehicleImport;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    $this->make = VehicleMake::create(['name' => 'MITSUBISHI']);
});

it('stores the full engine sheet normalizing valves cam type and nickname', function (): void {
    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $this->make->id,
        'engine_code' => '6G72',
        'nickname' => ' caballito ',
        'displacement' => '3.0L',
        'valves' => '24',
        'cam_type' => 'sohc',
        'cylinders' => 6,
        'fuel_type' => 'Gasoline',
    ])
        ->assertCreated()
        ->assertJsonPath('data.valves', '24V')
        ->assertJsonPath('data.cam_type', 'SOHC')
        ->assertJsonPath('data.cylinders', 6)
        ->assertJsonPath('data.nickname', 'CABALLITO');

    expect(VehicleEngine::firstOrFail()->full_description)->toBe('3.0L 24V SOHC 6G72 Gasoline');
});

it('allows several versions of one engine code but rejects an identical sheet', function (): void {
    $base = ['make_id' => $this->make->id, 'engine_code' => '6G72', 'displacement' => '3.0L', 'cam_type' => 'SOHC'];

    $this->postJson('/api/v1/vehicle-engines', [...$base, 'valves' => '12V'])->assertCreated();
    $this->postJson('/api/v1/vehicle-engines', [...$base, 'valves' => '24V'])->assertCreated();

    $this->postJson('/api/v1/vehicle-engines', [...$base, 'valves' => '24'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['engine_code']);

    expect(VehicleEngine::count())->toBe(2);
});

it('rejects an update that makes one version identical to another', function (): void {
    $twelve = VehicleEngine::create(['make_id' => $this->make->id, 'engine_code' => '6G72', 'displacement' => '3.0L', 'valves' => '12V', 'cam_type' => 'SOHC']);
    VehicleEngine::create(['make_id' => $this->make->id, 'engine_code' => '6G72', 'displacement' => '3.0L', 'valves' => '24V', 'cam_type' => 'SOHC']);

    $this->putJson("/api/v1/vehicle-engines/{$twelve->id}", ['valves' => '24V'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['engine_code']);

    $this->putJson("/api/v1/vehicle-engines/{$twelve->id}", ['cylinders' => 6])->assertOk();
});

it('validates engine sheet values with spanish messages', function (): void {
    $this->postJson('/api/v1/vehicle-engines', [
        'make_id' => $this->make->id,
        'engine_code' => '4G13',
        'valves' => 'doce',
        'cam_type' => 'TURBO',
        'cylinders' => 40,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'valves' => 'Las válvulas deben indicarse como número, por ejemplo 16 o 16V.',
            'cam_type' => 'El árbol de levas debe ser SOHC, DOHC u OHV.',
            'cylinders' => 'El número de cilindros debe estar entre 1 y 16.',
        ]);
});

it('searches engines and vehicles by valves cam type and nickname', function (): void {
    $toyota = VehicleMake::create(['name' => 'TOYOTA']);
    $hilux = VehicleModel::create(['make_id' => $toyota->id, 'name' => 'HILUX', 'generation' => 'N80', 'year_from' => 1990, 'year_to' => 2003]);
    $engine = VehicleEngine::create(['make_id' => $toyota->id, 'engine_code' => '2L', 'nickname' => 'BUZO', 'displacement' => '2.4L', 'valves' => '8V', 'cam_type' => 'SOHC', 'cylinders' => 4]);
    $engine->models()->sync([$hilux->id]);

    $this->getJson('/api/v1/vehicle-engines?search=buzo')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/vehicle-configurations?search=hilux%208v%20sohc')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.engine.valves', '8V')
        ->assertJsonPath('data.0.engine.cam_type', 'SOHC')
        ->assertJsonPath('data.0.engine.cylinders', 4)
        ->assertJsonPath('data.0.engine.nickname', 'BUZO');
});

it('imports engine versions from the specs column without merging them', function (): void {
    $import = app(VehicleImport::class);
    $row = fn (string $specs): array => [
        'make' => 'MITSUBISHI',
        'model' => 'MONTERO',
        'year' => '1991-1999',
        'code' => 'V20',
        'engine' => '6G72',
        'engine_specs' => $specs,
        'cylinders' => '6',
        'fuel_type' => 'GASOLINA',
    ];

    expect($import->validateRow($row('3.0L 12V SOHC')))->toBe([]);

    $import->processRow($row('3.0L 12V SOHC'));
    $import->processRow($row('3.0L 24V SOHC'));
    $import->processRow($row('3.0L 24V SOHC'));

    $versions = VehicleEngine::query()->where('engine_code', '6G72')->orderBy('valves')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions->pluck('valves')->all())->toBe(['12V', '24V'])
        ->and($versions->pluck('cam_type')->unique()->all())->toBe(['SOHC'])
        ->and($versions->pluck('cylinders')->unique()->all())->toBe([6])
        ->and($versions->pluck('displacement')->unique()->all())->toBe(['3.0L']);
});
