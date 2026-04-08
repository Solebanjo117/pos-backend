<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Check if running in essential mode (minimal functionality only)
        if (env('SEED_MODE') === 'essential' || app()->environment('production')) {
            $this->command->info('🌱 Iniciando seeder ESENCIAL (mínimo funcional)...');
            $this->call(EssentialSeeder::class);
            $this->command->info('🎉 Seeder esencial completado exitosamente!');
            return;
        }

        // Full demo seeder (development/testing)
        $this->command->info('🌱 Iniciando seeders COMPLETOS (con datos de demostración)...');

        $this->call([
            BrandSeeder::class,
            SupplierSeeder::class,
            WarehouseSeeder::class,
            AttributeDefinitionSeeder::class,
            CategorySeeder::class,
            DefaultSettingsSeeder::class,
            PaymentMethodSeeder::class,
            DemoDataSeeder::class,
            InventoryMovementSeeder::class,
            ProductInventorySeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
            VehicleSeeder::class,
        ]);

        $this->command->info('✅ Datos base creados');
        $this->command->info('✅ Vehículos creados');

        // Products with all relationships
        $this->call(ProductSeeder::class);
        $this->command->info('✅ Productos con relaciones creados');

        // Demo data: sales, purchases, customers, POS sessions
        $this->call(DemoDataSeeder::class);

        $this->command->info('🎉 Seeders completados exitosamente!');
    }
}
