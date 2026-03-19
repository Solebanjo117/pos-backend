<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            [
                'name' => 'Bosch',
                'description' => 'Líder mundial en tecnología automotriz y repuestos de alta calidad',
                'status' => 'active',
            ],
            [
                'name' => 'Brembo',
                'description' => 'Fabricante italiano especializado en sistemas de frenos de alto rendimiento',
                'status' => 'active',
            ],
            [
                'name' => 'NGK',
                'description' => 'Especialista en bujías y sensores de oxígeno',
                'status' => 'active',
            ],
            [
                'name' => 'Denso',
                'description' => 'Proveedor japonés de sistemas de climatización y componentes eléctricos',
                'status' => 'active',
            ],
            [
                'name' => 'Mann-Filter',
                'description' => 'Fabricante alemán de filtros automotrices premium',
                'status' => 'active',
            ],
            [
                'name' => 'Valeo',
                'description' => 'Líder francés en componentes automotrices inteligentes',
                'status' => 'active',
            ],
            [
                'name' => 'ACDelco',
                'description' => 'Marca de GM para repuestos y accesorios originales',
                'status' => 'active',
            ],
            [
                'name' => 'Monroe',
                'description' => 'Especialista en amortiguadores y sistemas de suspensión',
                'status' => 'active',
            ],
        ];

        foreach ($brands as $brand) {
            Brand::firstOrCreate(
                ['name' => $brand['name']],
                [
                    'description' => $brand['description'] ?? null,
                    'status' => $brand['status'] ?? 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
