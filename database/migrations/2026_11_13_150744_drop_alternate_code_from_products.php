<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quita products.alternate_code: los códigos secundarios viven en product_codes.
     * Ningún producto lo usaba (0 filas con valor en local y en producción).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'alternate_code')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('alternate_code');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'alternate_code')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('alternate_code', 100)->nullable()->after('sku_base');
        });
    }
};
