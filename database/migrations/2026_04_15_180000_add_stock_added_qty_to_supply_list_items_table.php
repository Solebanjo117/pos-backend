<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_list_items', function (Blueprint $table) {
            $table->integer('stock_added_qty')->default(0)->after('quantity_needed');
        });
    }

    public function down(): void
    {
        Schema::table('supply_list_items', function (Blueprint $table) {
            $table->dropColumn('stock_added_qty');
        });
    }
};
