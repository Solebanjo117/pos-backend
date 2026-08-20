<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Inventory
            ['allow_negative_inventory', false, 'boolean', 'inventory'],
            ['negative_inventory_warning', true, 'boolean', 'inventory'],

            // Store identity (used in PDF/tickets)
            ['store_name', 'HermeX POS', 'string', 'store'],
            ['store_currency', 'USD', 'string', 'store'],
            ['store_tagline', 'Sistema de Punto de Venta', 'string', 'store'],
            ['store_owner_name', '', 'string', 'store'],
            ['store_phone', '', 'string', 'store'],
            ['store_email', '', 'string', 'store'],
            ['store_address', '', 'string', 'store'],
            ['store_tax_id', '', 'string', 'store'],
            ['store_logo_url', '', 'string', 'store'],
            ['store_ticket_footer', '¡Gracias por su compra!', 'string', 'store'],

            // Regional/system preferences
            ['regional_timezone', 'America/Panama', 'string', 'regional'],
            ['regional_date_format', 'DD/MM/YYYY', 'string', 'regional'],
            ['regional_time_format', '24h', 'string', 'regional'],
            ['regional_datetime_format', 'DD/MM/YYYY HH:mm', 'string', 'regional'],
            ['regional_country', 'Panama', 'string', 'regional'],
            ['regional_decimal_separator', '.', 'string', 'regional'],
            ['regional_settings_active', true, 'boolean', 'regional'],

            // Pricing & tax
            ['store_tax_rate', 7.0, 'number', 'store'],
            ['pricing_formula', '', 'string', 'pricing'],
            ['price_rounding_increment', 1.00, 'number', 'pricing'],

            // Quotes
            ['quote_expiration_days', 30, 'number', 'quotes'],
            ['require_stock_for_quotes', false, 'boolean', 'quotes'],

            // Modules
            ['modules_work_orders_enabled', false, 'boolean', 'modules'],

            // SKU
            ['sku_format', json_encode(['category', 'brand']), 'json', 'sku'],
        ];

        foreach ($defaults as [$key, $value, $type, $group]) {
            // Only create if the setting doesn't exist yet — never overwrite user config
            if (! Setting::where('key', $key)->exists()) {
                Setting::set($key, $value, $type, $group);
            }
        }
    }
}
