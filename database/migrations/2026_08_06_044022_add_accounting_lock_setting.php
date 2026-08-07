<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'accounting_lock_date'],
            [
                'value' => '',
                'type' => 'string',
                'group' => 'accounting',
                'description' => 'Fecha hasta la cual los periodos contables permanecen cerrados',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'accounting_lock_date')->delete();
    }
};
