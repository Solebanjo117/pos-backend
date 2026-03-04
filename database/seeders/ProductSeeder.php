<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\AttributeDefinition;
use App\Models\VehicleEngine;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        echo "\n🌱 Iniciando seeders del módulo Product Detail...\n";
        
        // Relaciones Base
        $bosch = Brand::where('name', 'Bosch')->first();
        $brembo = Brand::where('name', 'Brembo')->first();
        $ngk = Brand::where('name', 'NGK')->first();
        $mannFilter = Brand::where('name', 'Mann-Filter')->first();
        
        $supplier1 = Supplier::first();
        $supplier2 = Supplier::skip(1)->first();
        
        $warehouse1 = Warehouse::first();
        $warehouse2 = Warehouse::skip(1)->first();
        
        // Categorías
        $catAlternadores = Category::where('name', 'Alternadores y Arrancadores')->first();
        $catFrenos = Category::where('name', 'Pastillas de Freno')->first();
        $catBujias = Category::where('name', 'Bujías')->first();
        $catFiltroAceite = Category::where('name', 'Filtros de Aceite')->first();
        $catBaterias = Category::where('name', 'Baterías')->first();
        
        // Líneas de Producto
        $premiumLine = ProductLine::create([
            'brand_id' => $bosch->id,
            'name' => 'Premium',
            'description' => 'Línea premium de alta calidad',
            'priority' => 1,
        ]);
        
        $standardLine = ProductLine::create([
            'brand_id' => $bosch->id,
            'name' => 'Standard',
            'description' => 'Línea estándar de calidad garantizada',
            'priority' => 2,
        ]);
        
        // Atributos base
        $amperaje = AttributeDefinition::where('name', 'Amperaje')->first();
        $voltaje = AttributeDefinition::where('name', 'Voltaje')->first();
        $material = AttributeDefinition::where('name', 'Material')->first();
        $capacidad = AttributeDefinition::where('name', 'Capacidad')->first();
        
        // ==================== PRODUCTO 1: ALTERNADOR ====================
        $alternador = Product::create([
            'sku_base' => 'ALT-12V-90A',
            'category_id' => $catAlternadores?->id,
            'product_line_id' => $premiumLine->id,
            'name' => 'Alternador 12V 90A',
            'description' => 'Alternador de alto rendimiento para uso pesado. Compatible con múltiples vehículos Ford y Chevrolet.',
            'status' => 'active',
        ]);
        
        if ($amperaje) {
            $alternador->attributes()->create(['name' => $amperaje->name, 'value' => '90', 'unit' => $amperaje->unit]);
        }
        if ($voltaje) {
            $alternador->attributes()->create(['name' => $voltaje->name, 'value' => '12', 'unit' => $voltaje->unit]);
        }
        
        
        $alternadorBosch = ProductVariant::create([
            'product_id' => $alternador->id,
            'brand_id' => $bosch->id,
            'sku' => 'ALT-12V-90A-BOSCH',
            'upc' => 'ALT-12V-90A-BOSCH-CUST',
            'status' => 'active',
            'is_default' => true,
        ]);
        
        $alternadorBosch->pricing()->create([
            'base_price' => 3500.00,
            'msrp' => 4500.00,
            'cost' => 2500.00,
            'margin_percentage' => 40.00,
            'tax_rate' => 7.00, // 7% ITBMS
            'max_discount_percentage' => 15.00,
        ]);
        
        $alternadorBosch->inventory()->create([
            'warehouse_id' => $warehouse1->id,
            'quantity_available' => 15,
            'quantity_reserved' => 2,
            'reorder_point' => 5,
            'reorder_quantity' => 20,
        ]);
        
        // Vehículos Compatibles
        $f150Engine = VehicleEngine::whereHas('model', function($q) {
            $q->where('name', 'F-150');
        })->where('engine_code', '3.5L V6 EcoBoost')->first();
        
        if ($f150Engine) {
            $alternador->compatibleEngines()->attach($f150Engine->id, ['notes' => 'Compatible con transmisión automática']);
        }
        
        // ==================== PRODUCTO 2: PASTILLAS DE FRENO ====================
        $pastillas = Product::create([
            'sku_base' => 'BRK-PAD-F',
            'category_id' => $catFrenos?->id,
            'product_line_id' => null,
            'name' => 'Pastillas de Freno Delanteras',
            'description' => 'Pastillas de freno de alto rendimiento. Excelente poder de frenado y durabilidad.',
            'status' => 'active',
        ]);
        
        if ($material) {
            $pastillas->attributes()->create(['name' => $material->name, 'value' => 'Cerámica', 'unit' => null]);
        }
        
        $pastillasBrembo = ProductVariant::create([
            'product_id' => $pastillas->id,
            'brand_id' => $brembo->id,
            'sku' => 'BRK-PAD-F-BREMBO',
            'upc' => 'BRK-PAD-F-BREMBO-CUST',
            'status' => 'active',
            'is_default' => true,
        ]);
        
        $pastillasBrembo->pricing()->create([
            'base_price' => 1250.00,
            'msrp' => 1500.00,
            'cost' => 892.86,
            'margin_percentage' => 40.00,
            'tax_rate' => 7.00,
            'max_discount_percentage' => 10.00,
        ]);
        
        $pastillasBrembo->inventory()->create([
            'warehouse_id' => $warehouse1->id,
            'quantity_available' => 45,
            'quantity_reserved' => 5,
            'reorder_point' => 15,
            'reorder_quantity' => 50,
        ]);
        
        // ==================== PRODUCTO 3: BUJÍAS (MULTI-MARCA) ====================
        $bujias = Product::create([
            'sku_base' => 'SPK-PLG-IRIDIUM',
            'category_id' => $catBujias?->id,
            'product_line_id' => null,
            'name' => 'Bujías Iridium (Set de 4)',
            'description' => 'Bujías de iridio de larga duración. Mayor eficiencia de combustión.',
            'status' => 'active',
        ]);
        
        // Variante NGK
        $bujiasNGK = ProductVariant::create([
            'product_id' => $bujias->id,
            'brand_id' => $ngk->id,
            'sku' => 'SPK-PLG-IRIDIUM-NGK',
            'upc' => 'SPK-PLG-IRIDIUM-NGK-CUST',
            'status' => 'active',
            'is_default' => true,
        ]);
        
        $bujiasNGK->pricing()->create([
            'base_price' => 850.00,
            'msrp' => 1000.00,
            'cost' => 607.14,
            'margin_percentage' => 40.00,
            'tax_rate' => 7.00,
            'max_discount_percentage' => 5.00,
        ]);
        
        $bujiasNGK->inventory()->create([
            'warehouse_id' => $warehouse1->id,
            'quantity_available' => 60,
            'quantity_reserved' => 8,
            'reorder_point' => 20,
            'reorder_quantity' => 80,
        ]);
        
        // Variante Bosch
        $bujiasBosch = ProductVariant::create([
            'product_id' => $bujias->id,
            'brand_id' => $bosch->id,
            'sku' => 'SPK-PLG-IRIDIUM-BOSCH',
            'upc' => 'SPK-PLG-IRIDIUM-BOSCH-CUST',
            'status' => 'active',
            'is_default' => false,
        ]);
        
        $bujiasBosch->pricing()->create([
            'base_price' => 920.00,
            'msrp' => 1100.00,
            'cost' => 657.14,
            'margin_percentage' => 40.00,
            'tax_rate' => 7.00,
            'max_discount_percentage' => 5.00,
        ]);
        
        $bujiasBosch->inventory()->create([
            'warehouse_id' => $warehouse1->id,
            'quantity_available' => 35,
            'quantity_reserved' => 2,
            'reorder_point' => 15,
            'reorder_quantity' => 50,
        ]);
        
        $bujias->oemReferences()->create([
            'oem_code' => 'LZFR5CIX',
            'manufacturer' => 'NGK',
        ]);
        
        // ==================== PRODUCTO 4: FILTRO DE ACEITE ====================
        $filtro = Product::create([
            'sku_base' => 'FIL-OIL-MF',
            'category_id' => $catFiltroAceite?->id,
            'product_line_id' => null,
            'name' => 'Filtro de Aceite',
            'description' => 'Filtro de aceite de alta eficiencia. Retiene partículas hasta 10 micrones.',
            'status' => 'active',
        ]);
        
        $filtroMann = ProductVariant::create([
            'product_id' => $filtro->id,
            'brand_id' => $mannFilter->id,
            'sku' => 'FIL-OIL-MF-MANN',
            'upc' => 'FIL-OIL-MF-MANN-CUST',
            'status' => 'active',
            'is_default' => true,
        ]);
        
        $filtroMann->pricing()->create([
            'base_price' => 180.00,
            'msrp' => 220.00,
            'cost' => 128.57, // base_price / 1.40
            'margin_percentage' => 40.00,
            'tax_rate' => 7.00,
            'max_discount_percentage' => 15.00,
        ]);
        
        $filtroMann->inventory()->create([
            'warehouse_id' => $warehouse1->id,
            'quantity_available' => 120,
            'quantity_reserved' => 15,
            'reorder_point' => 40,
            'reorder_quantity' => 150,
        ]);
        
        // ==================== PRODUCTO 5: BATERÍA ====================
        $bateria = Product::create([
            'sku_base' => 'BAT-12V-75AH',
            'category_id' => $catBaterias?->id,
            'product_line_id' => $premiumLine->id,
            'name' => 'Batería 12V 75Ah',
            'description' => 'Batería libre de mantenimiento con tecnología AGM.',
            'status' => 'active',
        ]);
        
        if ($capacidad) {
            $bateria->attributes()->create(['name' => $capacidad->name, 'value' => '75', 'unit' => $capacidad->unit]);
        }
        if ($voltaje) {
            $bateria->attributes()->create(['name' => $voltaje->name, 'value' => '12', 'unit' => $voltaje->unit]);
        }
        
        $bateriaBosch = ProductVariant::create([
            'product_id' => $bateria->id,
            'brand_id' => $bosch->id,
            'sku' => 'BAT-12V-75AH-BOSCH',
            'upc' => 'BAT-12V-75AH-BOSCH-CUST',
            'status' => 'active',
            'is_default' => true,
        ]);
        
        $bateriaBosch->pricing()->create([
            'base_price' => 2800.00,
            'msrp' => 3400.00,
            'cost' => 2000.00,
            'margin_percentage' => 40.00,
            'tax_rate' => 7.00,
            'max_discount_percentage' => 10.00,
        ]);
        
        $bateriaBosch->inventory()->create([
            'warehouse_id' => $warehouse1->id,
            'quantity_available' => 25,
            'quantity_reserved' => 3,
            'reorder_point' => 10,
            'reorder_quantity' => 30,
        ]);
        
        echo "✅ Productos con variantes creados\n";
    }
}
