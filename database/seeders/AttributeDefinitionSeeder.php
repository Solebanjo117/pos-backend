<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use Illuminate\Database\Seeder;

class AttributeDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $attributes = [
            // Atributos eléctricos
            [
                'name' => 'Voltaje',
                'data_type' => 'decimal',
                'unit' => 'V',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Amperaje',
                'data_type' => 'decimal',
                'unit' => 'A',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Potencia',
                'data_type' => 'decimal',
                'unit' => 'W',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            
            // Dimensiones
            [
                'name' => 'Largo',
                'data_type' => 'decimal',
                'unit' => 'mm',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Ancho',
                'data_type' => 'decimal',
                'unit' => 'mm',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Alto',
                'data_type' => 'decimal',
                'unit' => 'mm',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Diámetro',
                'data_type' => 'decimal',
                'unit' => 'mm',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            
            // Peso
            [
                'name' => 'Peso',
                'data_type' => 'decimal',
                'unit' => 'kg',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            
            // Capacidades
            [
                'name' => 'Capacidad',
                'data_type' => 'decimal',
                'unit' => 'L',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            
            // Presión
            [
                'name' => 'Presión',
                'data_type' => 'decimal',
                'unit' => 'PSI',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            
            // Temperatura
            [
                'name' => 'Temperatura de operación',
                'data_type' => 'decimal',
                'unit' => '°C',
                'applicable_categories' => null,
                'is_required' => false,
            ],
            
            // Atributos de texto
            [
                'name' => 'Material',
                'data_type' => 'string',
                'unit' => null,
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Color',
                'data_type' => 'string',
                'unit' => null,
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Tipo de conector',
                'data_type' => 'string',
                'unit' => null,
                'applicable_categories' => null,
                'is_required' => false,
            ],
            [
                'name' => 'Certificación',
                'data_type' => 'string',
                'unit' => null,
                'applicable_categories' => null,
                'is_required' => false,
            ],
        ];

        foreach ($attributes as $attribute) {
            AttributeDefinition::create($attribute);
        }
    }
}
