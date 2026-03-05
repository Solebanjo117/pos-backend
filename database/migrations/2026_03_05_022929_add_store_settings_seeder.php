<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['key' => 'store_name',         'value' => 'HermeX POS',         'type' => 'string', 'group' => 'store', 'description' => 'Nombre de la tienda / empresa'],
            ['key' => 'store_tagline',      'value' => 'Tu punto de venta inteligente', 'type' => 'string', 'group' => 'store', 'description' => 'Slogan o subtítulo'],
            ['key' => 'store_logo_url',     'value' => null,                  'type' => 'string', 'group' => 'store', 'description' => 'URL pública del logo'],
            ['key' => 'store_owner_name',   'value' => '',                    'type' => 'string', 'group' => 'store', 'description' => 'Nombre del propietario o responsable'],
            ['key' => 'store_phone',        'value' => '',                    'type' => 'string', 'group' => 'store', 'description' => 'Teléfono de contacto'],
            ['key' => 'store_email',        'value' => '',                    'type' => 'string', 'group' => 'store', 'description' => 'Correo de contacto'],
            ['key' => 'store_address',      'value' => '',                    'type' => 'string', 'group' => 'store', 'description' => 'Dirección física'],
            ['key' => 'store_tax_id',       'value' => '',                    'type' => 'string', 'group' => 'store', 'description' => 'RUC / Número de identificación fiscal'],
            ['key' => 'store_currency',     'value' => 'USD',                 'type' => 'string', 'group' => 'store', 'description' => 'Moneda principal'],
            ['key' => 'store_ticket_footer','value' => '¡Gracias por su compra!', 'type' => 'string', 'group' => 'store', 'description' => 'Pie de página en tickets y documentos'],
        ];

        foreach ($defaults as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'store_name', 'store_tagline', 'store_logo_url', 'store_owner_name',
            'store_phone', 'store_email', 'store_address', 'store_tax_id',
            'store_currency', 'store_ticket_footer',
        ])->delete();
    }
};
