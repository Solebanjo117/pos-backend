<?php

namespace Database\Seeders;

use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        // Ford
        $ford = VehicleMake::firstOrCreate(
            ['name' => 'Ford'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $f150 = VehicleModel::firstOrCreate(
            ['make_id' => $ford->id, 'name' => 'F-150'],
            [
                'year_from' => 2015,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $f150->id, 'engine_code' => '3.5L V6 EcoBoost'],
            [
                'displacement' => '3.5L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $f150->id, 'engine_code' => '5.0L V8'],
            [
                'displacement' => '5.0L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $fusion = VehicleModel::firstOrCreate(
            ['make_id' => $ford->id, 'name' => 'Fusion'],
            [
                'year_from' => 2013,
                'year_to' => 2020,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $fusion->id, 'engine_code' => '2.5L I4'],
            [
                'displacement' => '2.5L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Toyota
        $toyota = VehicleMake::firstOrCreate(
            ['name' => 'Toyota'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $corolla = VehicleModel::firstOrCreate(
            ['make_id' => $toyota->id, 'name' => 'Corolla'],
            [
                'year_from' => 2014,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $corolla->id, 'engine_code' => '1.8L I4'],
            [
                'displacement' => '1.8L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $camry = VehicleModel::firstOrCreate(
            ['make_id' => $toyota->id, 'name' => 'Camry'],
            [
                'year_from' => 2012,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $camry->id, 'engine_code' => '2.5L I4'],
            [
                'displacement' => '2.5L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $camry->id, 'engine_code' => '3.5L V6'],
            [
                'displacement' => '3.5L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Honda
        $honda = VehicleMake::firstOrCreate(
            ['name' => 'Honda'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $civic = VehicleModel::firstOrCreate(
            ['make_id' => $honda->id, 'name' => 'Civic'],
            [
                'year_from' => 2016,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $civic->id, 'engine_code' => '1.5L Turbo I4'],
            [
                'displacement' => '1.5L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $accord = VehicleModel::firstOrCreate(
            ['make_id' => $honda->id, 'name' => 'Accord'],
            [
                'year_from' => 2013,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $accord->id, 'engine_code' => '2.0L Turbo I4'],
            [
                'displacement' => '2.0L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Chevrolet
        $chevrolet = VehicleMake::firstOrCreate(
            ['name' => 'Chevrolet'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $silverado = VehicleModel::firstOrCreate(
            ['make_id' => $chevrolet->id, 'name' => 'Silverado 1500'],
            [
                'year_from' => 2014,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $silverado->id, 'engine_code' => '5.3L V8'],
            [
                'displacement' => '5.3L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $silverado->id, 'engine_code' => '6.2L V8'],
            [
                'displacement' => '6.2L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Nissan
        $nissan = VehicleMake::firstOrCreate(
            ['name' => 'Nissan'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $sentra = VehicleModel::firstOrCreate(
            ['make_id' => $nissan->id, 'name' => 'Sentra'],
            [
                'year_from' => 2013,
                'year_to' => 2023,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        VehicleEngine::firstOrCreate(
            ['model_id' => $sentra->id, 'engine_code' => '1.8L I4'],
            [
                'displacement' => '1.8L',
                'fuel_type' => 'Gasoline',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
