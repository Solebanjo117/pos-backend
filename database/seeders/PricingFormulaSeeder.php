<?php

namespace Database\Seeders;

use App\Models\PricingFormula;
use Illuminate\Database\Seeder;

class PricingFormulaSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            ['name' => 'Costo × 2',                    'formula' => 'costo * 2',                                  'sort_order' => 1],
            ['name' => 'Costo × 1.5 (redondeado)',     'formula' => 'ROUND(costo * 1.5)',                         'sort_order' => 2],
            ['name' => 'Costo × 2 (redondear arriba)', 'formula' => 'ROUNDUP(costo * 2)',                         'sort_order' => 3],
            ['name' => 'Costo × 2 (termina en .88)',   'formula' => 'FLOOR(costo * 2) + 0.88',                   'sort_order' => 4],
            ['name' => 'Costo × 2 (termina en .99)',   'formula' => 'FLOOR(costo * 2) + 0.99',                   'sort_order' => 5],
            ['name' => 'Costo + ITBM',                 'formula' => 'costo + costo * ITBM',                      'sort_order' => 6],
            ['name' => 'Costo × 1.5 + ITBM',           'formula' => 'ROUND(costo * 1.5 + costo * 1.5 * ITBM)', 'sort_order' => 7],
        ];

        foreach ($presets as $preset) {
            if (!PricingFormula::where('formula', $preset['formula'])->exists()) {
                PricingFormula::create(array_merge($preset, [
                    'is_preset'   => true,
                    'is_active'   => true,
                    'description' => null,
                ]));
            }
        }
    }
}
