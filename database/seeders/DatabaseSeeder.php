<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (env('SEED_MODE') === 'essential' || app()->environment('production')) {
            $this->command->info('Iniciando seeder ESENCIAL (minimo funcional)...');
            $this->call(EssentialSeeder::class);
            $this->command->info('Seeder esencial completado exitosamente!');

            return;
        }

        $this->command->info('Iniciando seeders COMPLETOS (con datos de demostracion)...');

        $this->call([
            BrandSeeder::class,
            SupplierSeeder::class,
            WarehouseSeeder::class,
            AttributeDefinitionSeeder::class,
            CategorySeeder::class,
            DefaultSettingsSeeder::class,
            PaymentMethodSeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
            VehicleSeeder::class,
            DemoDataSeeder::class,
            InventoryMovementSeeder::class,
            ProductInventorySeeder::class,
        ]);

        $this->command->info('Datos base creados');
        $this->command->info('Vehiculos creados');
        $this->command->info('Productos con relaciones creados');

        $this->call(InventoryFifoDemoSeeder::class);

        $this->command->info('Seeders completados exitosamente!');
    }
}
