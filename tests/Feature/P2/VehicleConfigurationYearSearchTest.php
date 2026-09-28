<?php

use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $this->actingAs($this->admin);
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Crea una configuración (modelo + motor emparejados) lista para búsquedas.
 *
 * @param  array<string, string|null>  $engineAttributes
 * @return array{0: VehicleMake, 1: VehicleModel, 2: VehicleEngine}
 */
function vehicleYearSearchConfiguration(
    string $makeName,
    string $modelName,
    int $yearFrom,
    int $yearTo,
    string $engineCode,
    ?string $generation = null,
    array $engineAttributes = [],
): array {
    $make = VehicleMake::firstOrCreate(['name' => $makeName]);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => $modelName,
        'year_from' => $yearFrom,
        'year_to' => $yearTo,
        'generation' => $generation,
    ]);
    $engine = VehicleEngine::create(array_merge([
        'make_id' => $make->id,
        'engine_code' => $engineCode,
    ], $engineAttributes));
    $engine->models()->attach($model->id);

    return [$make, $model, $engine];
}

/**
 * Indica si el esquema actual permite year_to nulo en vehicle_models.
 */
function vehicleModelsAllowNullYearTo(): bool
{
    $column = collect(Schema::getColumns('vehicle_models'))->firstWhere('name', 'year_to');

    return (bool) ($column['nullable'] ?? false);
}

// ─────────────────────────────────────────────────────────────────────────────
// Búsqueda por año
// ─────────────────────────────────────────────────────────────────────────────

it('filtra por cobertura de años cuando el token es un año', function (): void {
    vehicleYearSearchConfiguration('Toyota', 'Corolla', 2014, 2018, '2ZR-FE');
    vehicleYearSearchConfiguration('Toyota', 'Hilux', 2019, 2024, '1GD-FTV');

    $this->getJson('/api/v1/vehicle-configurations?search=2018')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Corolla');

    $this->getJson('/api/v1/vehicle-configurations?search=2020')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Hilux');

    $this->getJson('/api/v1/vehicle-configurations?search=2013')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('combina marca y año manteniendo la semántica AND entre tokens', function (): void {
    vehicleYearSearchConfiguration('Toyota', 'Corolla', 2014, 2018, '2ZR-FE');
    vehicleYearSearchConfiguration('Honda', 'Civic', 2014, 2018, 'R18Z1');
    vehicleYearSearchConfiguration('Toyota', 'Hilux', 2019, 2024, '1GD-FTV');

    $this->getJson('/api/v1/vehicle-configurations?search=toyota%202018')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.make.name', 'Toyota')
        ->assertJsonPath('data.0.model.name', 'Corolla');

    $this->getJson('/api/v1/vehicle-configurations?search=honda%202018')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Civic');

    $this->getJson('/api/v1/vehicle-configurations?search=toyota%20corolla%202018')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Corolla');

    $this->getJson('/api/v1/vehicle-configurations?search=honda%202019')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('incluye los modelos vigentes al buscar el año en curso', function (): void {
    $currentYear = (int) date('Y');
    vehicleYearSearchConfiguration('Toyota', 'Land Cruiser', 2015, $currentYear + 2, '1VD-FTV');
    vehicleYearSearchConfiguration('Toyota', 'Supra', 1998, 2002, '2JZ-GTE');

    $this->getJson("/api/v1/vehicle-configurations?search={$currentYear}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Land Cruiser');
});

it('trata un year_to nulo como rango abierto al buscar el año en curso', function (): void {
    $make = VehicleMake::create(['name' => 'Mazda']);
    $currentYear = (int) date('Y');
    $allowsNull = vehicleModelsAllowNullYearTo();

    $modelId = DB::table('vehicle_models')->insertGetId([
        'make_id' => $make->id,
        'name' => 'CX-5',
        'year_from' => 2017,
        'year_to' => $allowsNull ? null : $currentYear + 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $engine = VehicleEngine::create([
        'make_id' => $make->id,
        'engine_code' => 'SKYACTIV-G',
    ]);
    $engine->models()->attach($modelId);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $sql = mb_strtolower($query->sql);
        if (str_contains($sql, 'vehicle_pairs') && str_contains($sql, 'year_from')) {
            $queries[] = $sql;
        }
    });

    $this->getJson("/api/v1/vehicle-configurations?search={$currentYear}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'CX-5')
        ->assertJsonPath('data.0.model.year_to', $allowsNull ? null : $currentYear + 2);

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect($query)->toMatch('/year_to.{0,2}\s+is\s+null/');
    }
});

it('trata como texto los números de cuatro dígitos fuera del rango de años', function (): void {
    vehicleYearSearchConfiguration('Nissan', 'Sunny', 1990, 1995, 'K1800');

    $this->getJson('/api/v1/vehicle-configurations?search=1800')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.engine.engine_code', 'K1800');

    $this->getJson('/api/v1/vehicle-configurations?search=3500')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Búsqueda de texto
// ─────────────────────────────────────────────────────────────────────────────

it('amplía la búsqueda de texto a fuel_type y aspiration', function (): void {
    vehicleYearSearchConfiguration('Toyota', 'Hilux', 2019, 2024, '1GD-FTV', engineAttributes: [
        'displacement' => '2.8L',
        'fuel_type' => 'Diesel',
        'aspiration' => 'Turbo',
    ]);
    vehicleYearSearchConfiguration('Toyota', 'Corolla', 2014, 2018, '2ZR-FE', engineAttributes: [
        'displacement' => '1.8L',
        'fuel_type' => 'Gasoline',
        'aspiration' => 'Natural',
    ]);

    $this->getJson('/api/v1/vehicle-configurations?search=diesel')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Hilux');

    $this->getJson('/api/v1/vehicle-configurations?search=turbo')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Hilux');

    $this->getJson('/api/v1/vehicle-configurations?search=toyota%20diesel%202020')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Hilux');
});

it('mantiene la búsqueda de texto previa sobre marca modelo generación motor y vin', function (): void {
    [, $model, $engine] = vehicleYearSearchConfiguration(
        'Toyota',
        'Corolla',
        2014,
        2018,
        '2ZR-FE',
        'E170',
        ['displacement' => '1.8L'],
    );
    vehicleYearSearchConfiguration('Honda', 'Civic', 2014, 2018, 'R18Z1');
    VehicleVin::create([
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'vin' => 'JTDBR32E8A0123456',
    ]);

    $this->getJson('/api/v1/vehicle-configurations?search=Toyota%20Corolla%202ZR')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Corolla');

    $this->getJson('/api/v1/vehicle-configurations?search=e170')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/vehicle-configurations?search=1.8l')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/vehicle-configurations?search=jtdbr32e8a0123456')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.vins_count', 1);

    $this->getJson('/api/v1/vehicle-configurations?search=civic')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.model.name', 'Civic');
});
