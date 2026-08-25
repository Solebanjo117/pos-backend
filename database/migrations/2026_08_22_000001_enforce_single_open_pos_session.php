<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX pos_sessions_one_open_per_user_warehouse '
            .'ON pos_sessions (user_id, COALESCE(warehouse_id, 0)) '
            ."WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pos_sessions_one_open_per_user_warehouse');
    }
};
