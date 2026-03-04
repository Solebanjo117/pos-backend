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
        $this->command->info('🌱 Iniciando seeders del módulo Product Detail...');
        
        // Base data
        User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
            ]
        );

        $this->call([
            BrandSeeder::class,
            SupplierSeeder::class,
            WarehouseSeeder::class,
            AttributeDefinitionSeeder::class,
            CategorySeeder::class,
        ]);
        
        $this->command->info('✅ Datos base creados');
        
        // Vehicle data
        $this->call(VehicleSeeder::class);
        $this->command->info('✅ Vehículos creados');
        
        // Products with all relationships
        $this->call(ProductSeeder::class);
        $this->command->info('✅ Productos con relaciones creados');
        
        $this->command->info('🎉 Seeders completados exitosamente!');
    }
}
