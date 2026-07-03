<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'due_date')) {
                $table->date('due_date')->nullable()->after('expected_date');
                $table->index('due_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'due_date')) {
                $table->dropIndex(['due_date']);
                $table->dropColumn('due_date');
            }
        });
    }
};
