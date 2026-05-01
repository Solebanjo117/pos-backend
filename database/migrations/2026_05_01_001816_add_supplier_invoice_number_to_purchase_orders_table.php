<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'supplier_invoice_number')) {
                $table->string('supplier_invoice_number', 100)
                    ->nullable()
                    ->after('po_number')
                    ->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'supplier_invoice_number')) {
                $table->dropIndex(['supplier_invoice_number']);
                $table->dropColumn('supplier_invoice_number');
            }
        });
    }
};
