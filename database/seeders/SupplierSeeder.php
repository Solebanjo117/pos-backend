<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'name' => 'AutoZone Wholesale',
                'contact_name' => 'Carlos Méndez',
                'email' => 'compras@autozone.com',
                'phone' => '+52-555-1234567',
                'address' => 'Av. Insurgentes Sur 1234, CDMX',
                'payment_terms' => 'Net 30',
                'status' => 'active',
            ],
            [
                'name' => 'Parts Direct Mexico',
                'contact_name' => 'Ana Rodríguez',
                'email' => 'ventas@partsdirect.mx',
                'phone' => '+52-555-7654321',
                'address' => 'Blvd. Manuel Ávila Camacho 500, Tlalnepantla',
                'payment_terms' => 'Net 60',
                'status' => 'active',
            ],
            [
                'name' => 'Distribuidora Nacional de Refacciones',
                'contact_name' => 'Roberto Sánchez',
                'email' => 'contacto@dnr.com.mx',
                'phone' => '+52-555-9876543',
                'address' => 'Calz. Ignacio Zaragoza 2000, CDMX',
                'payment_terms' => 'Net 45',
                'status' => 'active',
            ],
            [
                'name' => 'Import Auto Parts',
                'contact_name' => 'Laura Martínez',
                'email' => 'info@importautoparts.com',
                'phone' => '+52-555-3334444',
                'address' => 'Calle Industria 789, Monterrey, NL',
                'payment_terms' => 'Net 30',
                'status' => 'active',
            ],
            [
                'name' => 'Global Auto Supply',
                'contact_name' => 'Miguel Hernández',
                'email' => 'ventas@globalautosupply.mx',
                'phone' => '+52-555-5556666',
                'address' => 'Av. Revolución 1500, Guadalajara, Jal',
                'payment_terms' => 'Net 30',
                'status' => 'active',
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::create($supplier);
        }
    }
}
