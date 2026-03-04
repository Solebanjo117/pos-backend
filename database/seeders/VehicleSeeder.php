<?php

namespace Database\Seeders;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\VehicleEngine;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        // Ford
        $ford = VehicleMake::create(['name' => 'Ford']);
        
        $f150 = VehicleModel::create([
            'make_id' => $ford->id,
            'name' => 'F-150',
            'year_from' => 2015,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $f150->id,
            'engine_code' => '3.5L V6 EcoBoost',
            'displacement' => '3.5L',
            'fuel_type' => 'Gasoline',
        ]);
        
        VehicleEngine::create([
            'model_id' => $f150->id,
            'engine_code' => '5.0L V8',
            'displacement' => '5.0L',
            'fuel_type' => 'Gasoline',
        ]);
        
        $fusion = VehicleModel::create([
            'make_id' => $ford->id,
            'name' => 'Fusion',
            'year_from' => 2013,
            'year_to' => 2020,
        ]);
        
        VehicleEngine::create([
            'model_id' => $fusion->id,
            'engine_code' => '2.5L I4',
            'displacement' => '2.5L',
            'fuel_type' => 'Gasoline',
        ]);
        
        // Toyota
        $toyota = VehicleMake::create(['name' => 'Toyota']);
        
        $corolla = VehicleModel::create([
            'make_id' => $toyota->id,
            'name' => 'Corolla',
            'year_from' => 2014,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $corolla->id,
            'engine_code' => '1.8L I4',
            'displacement' => '1.8L',
            'fuel_type' => 'Gasoline',
        ]);
        
        $camry = VehicleModel::create([
            'make_id' => $toyota->id,
            'name' => 'Camry',
            'year_from' => 2012,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $camry->id,
            'engine_code' => '2.5L I4',
            'displacement' => '2.5L',
            'fuel_type' => 'Gasoline',
        ]);
        
        VehicleEngine::create([
            'model_id' => $camry->id,
            'engine_code' => '3.5L V6',
            'displacement' => '3.5L',
            'fuel_type' => 'Gasoline',
        ]);
        
        // Honda
        $honda = VehicleMake::create(['name' => 'Honda']);
        
        $civic = VehicleModel::create([
            'make_id' => $honda->id,
            'name' => 'Civic',
            'year_from' => 2016,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $civic->id,
            'engine_code' => '1.5L Turbo I4',
            'displacement' => '1.5L',
            'fuel_type' => 'Gasoline',
        ]);
        
        $accord = VehicleModel::create([
            'make_id' => $honda->id,
            'name' => 'Accord',
            'year_from' => 2013,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $accord->id,
            'engine_code' => '2.0L Turbo I4',
            'displacement' => '2.0L',
            'fuel_type' => 'Gasoline',
        ]);
        
        // Chevrolet
        $chevrolet = VehicleMake::create(['name' => 'Chevrolet']);
        
        $silverado = VehicleModel::create([
            'make_id' => $chevrolet->id,
            'name' => 'Silverado 1500',
            'year_from' => 2014,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $silverado->id,
            'engine_code' => '5.3L V8',
            'displacement' => '5.3L',
            'fuel_type' => 'Gasoline',
        ]);
        
        VehicleEngine::create([
            'model_id' => $silverado->id,
            'engine_code' => '6.2L V8',
            'displacement' => '6.2L',
            'fuel_type' => 'Gasoline',
        ]);
        
        // Nissan
        $nissan = VehicleMake::create(['name' => 'Nissan']);
        
        $sentra = VehicleModel::create([
            'make_id' => $nissan->id,
            'name' => 'Sentra',
            'year_from' => 2013,
            'year_to' => 2023,
        ]);
        
        VehicleEngine::create([
            'model_id' => $sentra->id,
            'engine_code' => '1.8L I4',
            'displacement' => '1.8L',
            'fuel_type' => 'Gasoline',
        ]);
    }
}
