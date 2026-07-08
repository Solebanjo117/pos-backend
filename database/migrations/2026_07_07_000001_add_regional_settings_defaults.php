<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['key' => 'regional_timezone', 'value' => 'America/Panama', 'type' => 'string', 'group' => 'regional', 'description' => 'Zona horaria usada para mostrar fechas y rangos locales'],
            ['key' => 'regional_date_format', 'value' => 'DD/MM/YYYY', 'type' => 'string', 'group' => 'regional', 'description' => 'Formato visual de fecha'],
            ['key' => 'regional_time_format', 'value' => '24h', 'type' => 'string', 'group' => 'regional', 'description' => 'Formato visual de hora'],
            ['key' => 'regional_datetime_format', 'value' => 'DD/MM/YYYY HH:mm', 'type' => 'string', 'group' => 'regional', 'description' => 'Formato visual de fecha y hora'],
            ['key' => 'regional_country', 'value' => 'Panama', 'type' => 'string', 'group' => 'regional', 'description' => 'Pais o region operativa'],
            ['key' => 'regional_decimal_separator', 'value' => '.', 'type' => 'string', 'group' => 'regional', 'description' => 'Separador decimal usado para mostrar importes'],
            ['key' => 'regional_settings_active', 'value' => 'true', 'type' => 'boolean', 'group' => 'regional', 'description' => 'Activa preferencias regionales del sistema'],
        ];

        foreach ($defaults as $setting) {
            if (! DB::table('settings')->where('key', $setting['key'])->exists()) {
                DB::table('settings')->insert(array_merge($setting, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'regional_timezone',
            'regional_date_format',
            'regional_time_format',
            'regional_datetime_format',
            'regional_country',
            'regional_decimal_separator',
            'regional_settings_active',
        ])->delete();
    }
};
