<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('store_tax_rate', 7.0, 'number', 'store');
        Setting::set('modules_work_orders_enabled', false, 'boolean', 'modules');
    }
}
