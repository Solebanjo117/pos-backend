<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'code' => 'WH-CDMX-01',
                'name' => 'Almacén Principal CDMX',
                'address' => 'Av. Politécnico 1234, Col. Lindavista, CDMX',
                'is_active' => true,
            ],
            [
                'code' => 'WH-MTY-01',
                'name' => 'Almacén Monterrey',
                'address' => 'Carretera Nacional Km 5, Monterrey, NL',
                'is_active' => true,
            ],
            [
                'code' => 'WH-GDL-01',
                'name' => 'Almacén Guadalajara',
                'address' => 'Periférico Norte 3000, Zapopan, Jal',
                'is_active' => true,
            ],
            [
                'code' => 'WH-CDMX-02',
                'name' => 'Almacén Satélite',
                'address' => 'Blvd. Manuel Ávila Camacho 200, Tlalnepantla, Edo. Mex',
                'is_active' => true,
            ],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::create($warehouse);
        }
    }
}
