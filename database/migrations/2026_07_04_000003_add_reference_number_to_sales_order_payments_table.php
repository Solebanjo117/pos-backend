<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_order_payments', 'reference_number')) {
                $table->string('reference_number', 100)->nullable()->after('method')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_order_payments', 'reference_number')) {
                $table->dropColumn('reference_number');
            }
        });
    }
};
