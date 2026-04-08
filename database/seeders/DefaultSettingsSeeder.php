<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // ==================== INVENTORY SETTINGS ====================
        // Critical for sales order and inventory management
        Setting::set('allow_negative_inventory', true, 'boolean', 'inventory');
        Setting::set('negative_inventory_warning', true, 'boolean', 'inventory');

        // ==================== STORE IDENTITY ====================
        // Used in documents, PDF generation, and frontend branding
        Setting::set('store_name', 'HermeX POS', 'string', 'store');
        Setting::set('store_currency', 'USD', 'string', 'store');
        Setting::set('store_tagline', 'Sistema de Punto de Venta', 'string', 'store');
        Setting::set('store_owner_name', '', 'string', 'store');
        Setting::set('store_phone', '', 'string', 'store');
        Setting::set('store_email', '', 'string', 'store');
        Setting::set('store_address', '', 'string', 'store');
        Setting::set('store_tax_id', '', 'string', 'store');
        Setting::set('store_logo_url', null, 'string', 'store');
        Setting::set('store_ticket_footer', '¡Gracias por su compra!', 'string', 'store');

        // ==================== PRICING & TAX ====================
        // Critical for price calculations
        Setting::set('store_tax_rate', 7.0, 'number', 'store');
        Setting::set('pricing_formula', '', 'string', 'pricing');
        Setting::set('price_rounding_increment', 1.00, 'number', 'pricing');

        // ==================== QUOTES & SALES ====================
        Setting::set('quote_expiration_days', 30, 'number', 'quotes');

        // ==================== MODULES & FEATURES ====================
        Setting::set('modules_work_orders_enabled', false, 'boolean', 'modules');

        // ==================== PRODUCT SKU ====================
        Setting::set('sku_format', json_encode(['category', 'brand']), 'json', 'sku');
    }
}
