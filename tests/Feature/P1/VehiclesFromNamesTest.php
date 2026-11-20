<?php

use App\Console\Commands\VehiclesFromProductNames;
use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Decisión de Luis (2026-11-18): el carro escrito en el nombre del producto se guarda como carro
 * compatible. Si existe sin ambigüedad en el catálogo se vincula; si no, queda como texto.
 */
function parsedVehicles(string $name): array
{
    return collect(app(VehiclesFromProductNames::class)->parse($name))->pluck('text')->all();
}

it('reads the car, its chassis and years from real product names', function () {
    expect(parsedVehicles('TERMINAL CREM NIS SENTRA B17 12- M14x1.50RACK M12x1.25TIE'))->toBe(['Nissan SENTRA B17 2012+'])
        ->and(parsedVehicles('TERMINAL CREM HYUN ACCENT 96- 00- LH RH M16x1.50RACK M14x1.50TIE 57755-25000 CRKH20'))->toBe(['Hyundai ACCENT 1996-2000'])
        ->and(parsedVehicles('TERMINAL CREM TOY DAIH TERIOS 06- LH RH M14x1.50RACK M12x1.25TIE'))->toBe(['Toyota/Daihatsu TERIOS 2006+'])
        ->and(parsedVehicles('TERMINAL CREM MIT L300 90- HYUN H100 RH LH M17x1.00RACK M14x1.50TIE'))->toBe(['Mitsubishi L300 1990+', 'Hyundai H100'])
        ->and(parsedVehicles('TERMINAL CREM CHEV MONZA CORSA H14x1.50RACK M12x1.50TIE'))->toBe(['Chevrolet MONZA CORSA'])
        ->and(parsedVehicles('TERMINAL CREM SUZ ALTO 800 F8D K10 HEMBRA'))->toBe(['Suzuki ALTO 800 F8D K10'])
        ->and(parsedVehicles('TERMINAL CREM M18X1.50RACK M12X1.25TIE HON FIT 06- RH SR-H520'))->toBe(['Honda FIT 2006+'])
        ->and(parsedVehicles('Filtro de aceite roscado PH3614'))->toBe([]);
});

it('links the catalog model only for an exact model and chassis, otherwise keeps the full text', function () {
    $warehouse = Warehouse::create(['code' => 'W-VEH', 'name' => 'Almacén']);
    $user = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);

    $toyota = VehicleMake::create(['name' => 'TOYOTA']);
    $corollaE90 = VehicleModel::create(['make_id' => $toyota->id, 'name' => 'COROLLA E90', 'year_from' => 1986, 'year_to' => 1989]);
    VehicleModel::create(['make_id' => $toyota->id, 'name' => 'RAV4 A20', 'year_from' => 2000, 'year_to' => 2005]);

    $exact = Product::create(['sku_base' => 'T-1', 'name' => 'TERMINAL CREM TOY COROLLA 90- E90 M14x1.50RACK M14x1.50TIE', 'status' => 'active']);
    $twoChassis = Product::create(['sku_base' => 'T-2', 'name' => 'TERMINAL CREM TOY RAV4 00- 06- A20 A30 LH RH M14x1.50RACK', 'status' => 'active']);
    $nissan = Product::create(['sku_base' => 'T-3', 'name' => 'TERMINAL CREM NIS SENTRA B17 12- M14x1.50RACK', 'status' => 'active']);
    $withCar = Product::create(['sku_base' => 'T-4', 'name' => 'TERMINAL CREM HYUN I10 M16x1.50RACK', 'status' => 'active']);
    ProductVehicleCompatibility::create(['product_id' => $withCar->id, 'scope_type' => 'raw', 'raw_vehicle_text' => 'Ya cargado', 'raw_vehicle_key' => 'ya cargado', 'is_default' => true]);

    $this->artisan('catalog:vehicles-from-names')->expectsOutputToContain('Modo simulación')->assertSuccessful();
    expect(ProductVehicleCompatibility::count())->toBe(1);

    $this->artisan('catalog:vehicles-from-names --apply')->assertSuccessful();

    $linked = ProductVehicleCompatibility::where('product_id', $exact->id)->sole();
    expect($linked->scope_type)->toBe('model')
        ->and($linked->model_id)->toBe($corollaE90->id)
        ->and($linked->is_default)->toBeTrue()
        ->and(ProductVehicleCompatibility::where('product_id', $twoChassis->id)->sole()->raw_vehicle_text)->toBe('Toyota RAV4 A20 A30 2000-2006')
        ->and(ProductVehicleCompatibility::where('product_id', $nissan->id)->sole()->only(['scope_type', 'raw_vehicle_text', 'raw_vehicle_key']))
        ->toBe(['scope_type' => 'raw', 'raw_vehicle_text' => 'Nissan SENTRA B17 2012+', 'raw_vehicle_key' => 'nissan sentra b17 2012+'])
        ->and(ProductVehicleCompatibility::where('product_id', $withCar->id)->pluck('raw_vehicle_text')->all())->toBe(['Ya cargado']);

    // La columna CARRO de Productos lo muestra.
    $row = collect($this->getJson('/api/v1/products?search=SENTRA')->assertOk()->json('data'))->firstWhere('id', $nissan->id);
    expect(collect($row['vehicles'])->pluck('label')->implode(' '))->toContain('Nissan SENTRA B17 2012+');
});
