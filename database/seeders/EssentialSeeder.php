<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class EssentialSeeder extends Seeder
{
    /**
     * Run the database seeds for essential/minimal functionality.
     *
     * Creates only the minimum required data for the application to function:
     * - 1 generic brand (required by product_variants)
     * - 1 generic category (required for SKU generation)
     * - 1 generic supplier (required by purchase_orders)
     * - 1 default warehouse (required for inventory and sales)
     * - 1 admin user (required for authentication)
     * - Payment methods and settings
     */
    public function run(): void
    {
        $this->command->info('🌱 Iniciando seeder esencial (mínimo funcional)...');

        // Step 1: Create essential brand
        $this->command->line('📦 Creando marca genérica...');
        $brand = Brand::firstOrCreate(
            ['name' => 'Genérica'],
            [
                'description' => 'Marca genérica para productos sin marca específica',
                'status' => 'active',
            ]
        );
        $this->command->info('✅ Brand: Genérica');

        // Step 2: Create essential category
        $this->command->line('📂 Creando categoría general...');
        $category = Category::firstOrCreate(
            ['slug' => 'general'],
            [
                'name' => 'General',
                'description' => 'Categoría general para productos',
                'parent_id' => null,
                'priority' => 0,
            ]
        );
        $this->command->info('✅ Category: General');

        // Step 3: Create essential supplier
        $this->command->line('🏭 Creando proveedor general...');
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Proveedor General'],
            [
                'payment_terms' => 'Net 30',
                'status' => 'active',
            ]
        );
        $this->command->info('✅ Supplier: Proveedor General');

        // Step 4: Create default warehouse
        $this->command->line('🏪 Creando almacén principal...');
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'WH-MAIN-01'],
            [
                'name' => 'Almacén Principal',
                'status' => 'active',
            ]
        );
        $this->command->info('✅ Warehouse: Almacén Principal (WH-MAIN-01)');

        // Step 5: Create admin user
        $this->command->line('👤 Creando usuario administrador...');
        $admin = User::firstOrCreate(
            ['email' => 'admin@hermexpos.app'],
            [
                'name' => 'Administrador',
                'username' => 'admin',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true,
                'can_manage_financial_adjustments' => true,
                'current_warehouse_id' => $warehouse->id,
                'email_verified_at' => now(),
            ]
        );

        // If user already existed but has no warehouse assigned, set it
        if (! $admin->wasRecentlyCreated) {
            $admin->update([
                'current_warehouse_id' => $admin->current_warehouse_id ?: $warehouse->id,
                'can_manage_financial_adjustments' => true,
            ]);
        }

        $this->command->info('✅ User: admin@hermexpos.app (password: password)');

        // Step 6: Link admin user with warehouse
        $this->command->line('🔗 Vinculando usuario con almacén...');
        $admin->warehouses()->syncWithoutDetaching([$warehouse->id]);
        $this->command->info('✅ Admin vinculado a warehouse');

        // Step 7: Seed payment methods
        $this->command->line('💳 Creando métodos de pago...');
        $this->call(PaymentMethodSeeder::class);
        $this->command->info('✅ Payment methods creados');

        // Step 8: Seed settings
        $this->command->line('⚙️ Creando configuraciones...');
        $this->call(DefaultSettingsSeeder::class);
        $this->command->info('✅ Settings creados');

        $this->command->info('');
        $this->command->info('✅ Seeder esencial completado exitosamente!');
        $this->command->info('');
        $this->command->line('📊 Datos creados:');
        $this->command->line("  • Brand:    Genérica (ID: {$brand->id})");
        $this->command->line("  • Category: General (ID: {$category->id})");
        $this->command->line("  • Supplier: Proveedor General (ID: {$supplier->id})");
        $this->command->line("  • Warehouse: Almacén Principal (ID: {$warehouse->id})");
        $this->command->line("  • Admin User: admin@hermexpos.app (ID: {$admin->id})");
        $this->command->line('');
        $this->command->line('🔐 Credentials de prueba:');
        $this->command->line('  Email: admin@hermexpos.app');
        $this->command->line('  Password: password');
        $this->command->info('');
    }
}
