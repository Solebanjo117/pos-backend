<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        echo "\n🌱 Creando categorías de productos automotrices...\n";

        // ==================== CATEGORÍAS PRINCIPALES ====================
        
        // 1. Sistema Eléctrico
        $electrical = Category::create([
            'name' => 'Sistema Eléctrico',
            'slug' => 'sistema-electrico',
            'description' => 'Componentes eléctricos y electrónicos del vehículo',
            'icon' => 'zap',
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Baterías',
            'parent_id' => $electrical->id,
            'description' => 'Baterías automotrices',
            'icon' => 'battery',
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Alternadores y Arrancadores',
            'parent_id' => $electrical->id,
            'description' => 'Alternadores, arrancadores y marcha',
            'icon' => 'rotate-cw',
            'priority' => 2,
        ]);

        Category::create([
            'name' => 'Iluminación',
            'parent_id' => $electrical->id,
            'description' => 'Faros, luces y componentes de iluminación',
            'icon' => 'lightbulb',
            'priority' => 3,
        ]);

        // 2. Motor
        $motor = Category::create([
            'name' => 'Motor',
            'slug' => 'motor',
            'description' => 'Componentes del motor',
            'icon' => 'cpu',
            'priority' => 2,
        ]);

        $filtros = Category::create([
            'name' => 'Filtros',
            'parent_id' => $motor->id,
            'description' => 'Filtros de aceite, aire, combustible',
            'icon' => 'filter',
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Filtros de Aceite',
            'parent_id' => $filtros->id,
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Filtros de Aire',
            'parent_id' => $filtros->id,
            'priority' => 2,
        ]);

        Category::create([
            'name' => 'Filtros de Combustible',
            'parent_id' => $filtros->id,
            'priority' => 3,
        ]);

        $encendido = Category::create([
            'name' => 'Sistema de Encendido',
            'parent_id' => $motor->id,
            'description' => 'Bujías, bobinas, cables',
            'icon' => 'zap-off',
            'priority' => 2,
        ]);

        Category::create([
            'name' => 'Bujías',
            'parent_id' => $encendido->id,
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Bobinas de Encendido',
            'parent_id' => $encendido->id,
            'priority' => 2,
        ]);

        // 3. Frenos
        $brakes = Category::create([
            'name' => 'Frenos',
            'slug' => 'frenos',
            'description' => 'Sistema de frenado completo',
            'icon' => 'disc',
            'priority' => 3,
        ]);

        Category::create([
            'name' => 'Pastillas de Freno',
            'parent_id' => $brakes->id,
            'description' => 'Pastillas delanteras y traseras',
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Discos de Freno',
            'parent_id' => $brakes->id,
            'description' => 'Discos delanteros y traseros',
            'priority' => 2,
        ]);

        Category::create([
            'name' => 'Tambores y Zapatas',
            'parent_id' => $brakes->id,
            'description' => 'Tambores y zapatas de freno',
            'priority' => 3,
        ]);

        Category::create([
            'name' => 'Fluidos y Componentes',
            'parent_id' => $brakes->id,
            'description' => 'Líquido de frenos, mangueras, sensores',
            'priority' => 4,
        ]);

        // 4. Suspensión
        $suspension = Category::create([
            'name' => 'Suspensión y Dirección',
            'slug' => 'suspension-direccion',
            'description' => 'Componentes de suspensión y dirección',
            'icon' => 'gauge',
            'priority' => 4,
        ]);

        Category::create([
            'name' => 'Amortiguadores',
            'parent_id' => $suspension->id,
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Rótulas y Terminales',
            'parent_id' => $suspension->id,
            'priority' => 2,
        ]);

        Category::create([
            'name' => 'Muelles y Resortes',
            'parent_id' => $suspension->id,
            'priority' => 3,
        ]);

        // 5. Transmisión
        $transmission = Category::create([
            'name' => 'Transmisión',
            'slug' => 'transmision',
            'description' => 'Componentes de la transmisión',
            'icon' => 'settings',
            'priority' => 5,
        ]);

        Category::create([
            'name' => 'Embragues',
            'parent_id' => $transmission->id,
            'priority' => 1,
        ]);

        Category::create([
            'name' => 'Sincronizados y Kits',
            'parent_id' => $transmission->id,
            'priority' => 2,
        ]);

        // 6. Accesorios
        Category::create([
            'name' => 'Aceites y Lubricantes',
            'slug' => 'aceites-lubricantes',
            'description' => 'Aceites de motor, transmisión y lubricantes',
            'icon' => 'droplet',
            'priority' => 6,
        ]);

        Category::create([
            'name' => 'Accesorios y Herramientas',
            'slug' => 'accesorios-herramientas',
            'description' => 'Accesorios diversos y herramientas',
            'icon' => 'wrench',
            'priority' => 7,
        ]);

        echo "✅ Categorías creadas exitosamente.\n";
    }
}
