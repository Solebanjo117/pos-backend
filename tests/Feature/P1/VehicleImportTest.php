<?php

use App\Imports\VehicleImport;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleVin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
});

function vehicleImportFile(array $rows, string $format): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['index', 'Make Name', 'Engine Code', 'Engine Specs', 'Model Name', 'Years', 'VIN'],
        ...$rows,
    ], null, 'A1');

    $path = sys_get_temp_dir().'/vehicle-import-'.bin2hex(random_bytes(8)).'.'.$format;
    IOFactory::createWriter($spreadsheet, $format === 'csv' ? 'Csv' : 'Xlsx')->save($path);
    $spreadsheet->disconnectWorksheets();

    test()->beforeApplicationDestroyed(function () use ($path) {
        gc_collect_cycles();

        if (file_exists($path)) {
            unlink($path);
        }
    });

    return new UploadedFile($path, 'vehicle_database.'.$format, null, null, true);
}

it('creates and reuses vehicle makes and models from named spreadsheet columns', function (string $format, bool $existing) {
    $make = $existing ? VehicleMake::create(['name' => 'Toyota']) : null;
    $model = $make?->models()->create([
        'name' => 'Yaris P10',
        'year_from' => 2000,
        'year_to' => 2005,
    ]);

    $file = vehicleImportFile([
        [0, 'TOYOTA', '1NZFE', '1.5L 16V DOHC', 'YARIS P10', '2000-2005', 'FGHJRT'],
        [1, ' toyota ', '2NZFE', '1.3L 16V DOHC', ' yaris p10 ', '2000-2005', ''],
        [2, 'TOYOTA', '1NZFE', '1.5L 16V DOHC', 'YARIS P90', '2006-2013', ''],
        [3, 'MITSUBISHI', '4G13', '1.3L 12V SOHC', 'LANCER CB', '1990-1995', ''],
        [4, 'TOYOTA', '1NZFE', '1.5L 16V DOHC', 'YARIS P10', '2000-2005', 'FGHJRT'],
    ], $format);

    $preview = $this->post('/api/v1/vehicles/import/preview', ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('data.count', 5)
        ->assertJsonPath('data.rows.0.data.make', 'TOYOTA')
        ->assertJsonPath('data.rows.0.data.model', 'YARIS P10')
        ->assertJsonPath('data.rows.0.data.engine', '1NZFE')
        ->assertJsonPath('data.rows.0.data.year', '2000-2005');

    expect(collect($preview->json('data.rows'))->every('is_valid'))->toBeTrue()
        ->and(VehicleMake::count())->toBe($existing ? 1 : 0)
        ->and(VehicleModel::count())->toBe($existing ? 1 : 0);

    $rows = array_column($preview->json('data.rows'), 'data');

    foreach ([1, 2] as $attempt) {
        $this->postJson('/api/v1/vehicles/import/execute', ['rows' => $rows])
            ->assertSuccessful()
            ->assertJsonPath('data.success_count', 5)
            ->assertJsonPath('data.error_count', 0);
    }

    $this->assertDatabaseCount('vehicle_makes', 2);
    $this->assertDatabaseCount('vehicle_models', 3);
    $this->assertDatabaseCount('vehicle_engines', 3);
    $this->assertDatabaseCount('vehicle_engine_model', 4);
    $this->assertDatabaseCount('vehicle_vins', 1);
    $this->assertDatabaseCount('vehicle_chassis', 0);

    $yaris = VehicleModel::where('name', 'YARIS P10')->firstOrFail();
    $engine = VehicleEngine::where('engine_code', '1NZFE')->firstOrFail();

    expect($yaris->make->name)->toBe($existing ? 'Toyota' : 'TOYOTA')
        ->and($yaris->year_from)->toBe(2000)
        ->and($yaris->year_to)->toBe(2005)
        ->and($yaris->enginesMany()->count())->toBe(2)
        ->and($engine->models()->count())->toBe(2)
        ->and($engine->make_id)->toBe($yaris->make_id)
        ->and(VehicleVin::firstOrFail()->model_id)->toBe($yaris->id)
        ->and(VehicleVin::firstOrFail()->engine_id)->toBe($engine->id);

    if ($existing) {
        expect($yaris->id)->toBe($model->id)
            ->and($yaris->make_id)->toBe($make->id);
    }
})->with(['csv', 'xlsx'])->with([false, true]);

it('rejects empty make and model cells without creating incomplete catalog records', function (string $format) {
    $file = vehicleImportFile([
        [0, 'TOYOTA', '', '', 'YARIS P10', '2000-2005', ''],
        [1, '', '', '', 'WITHOUT MAKE', '2000-2005', ''],
        [2, 'WITHOUT MODEL', '', '', '', '2000-2005', ''],
        [3, '   ', '', '', '   ', '2000-2005', ''],
        ['', '', '', '', '', '', ''],
    ], $format);

    $preview = $this->post('/api/v1/vehicles/import/preview', ['file' => $file])
        ->assertSuccessful()
        ->assertJsonPath('data.count', 4)
        ->assertJsonPath('data.rows.0.is_valid', true)
        ->assertJsonPath('data.rows.1.is_valid', false)
        ->assertJsonPath('data.rows.2.is_valid', false)
        ->assertJsonPath('data.rows.3.is_valid', false);

    $this->assertDatabaseCount('vehicle_makes', 0);
    $this->assertDatabaseCount('vehicle_models', 0);

    $this->postJson('/api/v1/vehicles/import/execute', [
        'rows' => array_column($preview->json('data.rows'), 'data'),
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.success_count', 1)
        ->assertJsonPath('data.error_count', 3)
        ->assertJsonPath('data.errors.0.error', 'El campo marca (MAKE) es obligatorio.')
        ->assertJsonPath('data.errors.1.error', 'El campo modelo (MODEL) es obligatorio.');

    $this->assertDatabaseCount('vehicle_makes', 1);
    $this->assertDatabaseCount('vehicle_models', 1);
    $this->assertDatabaseMissing('vehicle_makes', ['name' => 'WITHOUT MODEL']);
})->with(['csv', 'xlsx']);

it('keeps matching model names scoped to their make and year range', function () {
    $import = new VehicleImport;
    $import->execute([
        ['__row' => 2, 'make' => 'MAKE A', 'model' => 'MODEL', 'year' => '2000-2005'],
        ['__row' => 3, 'make' => 'MAKE B', 'model' => 'MODEL', 'year' => '2000-2005'],
        ['__row' => 4, 'make' => 'MAKE A', 'model' => 'MODEL', 'year' => '2006-2010'],
        ['__row' => 5, 'make' => 'make a', 'model' => 'model', 'year' => '2000-2005'],
    ]);

    expect($import->successCount)->toBe(4)
        ->and($import->errorCount)->toBe(0)
        ->and($import->results[0]['id'])->toBe($import->results[3]['id'])
        ->and($import->results[0]['id'])->not->toBe($import->results[1]['id'])
        ->and($import->results[0]['id'])->not->toBe($import->results[2]['id']);

    $this->assertDatabaseCount('vehicle_makes', 2);
    $this->assertDatabaseCount('vehicle_models', 3);
});

it('imports the exported vehicle database csv creating missing makes and reading engine specs', function () {
    VehicleMake::create(['name' => 'TOYOTA']);

    $path = sys_get_temp_dir().'/vehicle-database-'.bin2hex(random_bytes(8)).'.csv';
    file_put_contents($path, implode("\n", [
        '"Make Name","Engine Code","Engine Specs","Model Name","Years","VIN"',
        '"BMW","S54B32","3.2L NA I6 (338 hp)","M3 E46","2000-2006","WBSBL9340001001"',
        '"BMW","S54B32HP","3.2L High Output I6 CSL (355 hp)","M3 E46","2000-2006","WBSBL9340001003"',
        '"TOYOTA","1NZFE","1.5L 16V DOHC","YARIS P10","2000-2005","FGHJRT"',
        '"ISUZU","4JK1-TC","2.5 L","DMAX RA RC","2000-2011",""',
        '"ISUZU","1KDFTV","3.0L 16V DOHC Turbo Diesel","DMAX RT","2012-2017",""',
        '',
    ]));
    test()->beforeApplicationDestroyed(fn () => file_exists($path) && unlink($path));

    $preview = $this->post('/api/v1/vehicles/import/preview', [
        'file' => new UploadedFile($path, 'vehicle_database_2026-10-06.csv', 'text/csv', null, true),
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.count', 5)
        ->assertJsonPath('data.rows.0.data.make', 'BMW')
        ->assertJsonPath('data.rows.0.data.displacement', '3.2L')
        ->assertJsonPath('data.rows.0.data.aspiration', 'Naturally Aspirated')
        ->assertJsonPath('data.rows.3.data.displacement', '2.5L')
        ->assertJsonPath('data.rows.4.data.aspiration', 'Turbo')
        ->assertJsonPath('data.rows.4.data.fuel_type', 'Diesel');

    expect(collect($preview->json('data.rows'))->every('is_valid'))->toBeTrue();

    $this->postJson('/api/v1/vehicles/import/execute', [
        'rows' => array_column($preview->json('data.rows'), 'data'),
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.success_count', 5)
        ->assertJsonPath('data.error_count', 0);

    expect(VehicleMake::query()->orderBy('name')->pluck('name')->all())->toBe(['BMW', 'ISUZU', 'TOYOTA'])
        ->and(VehicleEngine::where('engine_code', 'S54B32')->firstOrFail()->displacement)->toBe('3.2L')
        ->and(VehicleEngine::where('engine_code', 'S54B32')->firstOrFail()->aspiration)->toBe('Naturally Aspirated')
        ->and(VehicleEngine::where('engine_code', 'S54B32HP')->firstOrFail()->aspiration)->toBeNull()
        ->and(VehicleEngine::where('engine_code', '4JK1-TC')->firstOrFail()->displacement)->toBe('2.5L')
        ->and(VehicleEngine::where('engine_code', '1KDFTV')->firstOrFail()->fuel_type)->toBe('Diesel')
        ->and(VehicleVin::count())->toBe(3);
});
