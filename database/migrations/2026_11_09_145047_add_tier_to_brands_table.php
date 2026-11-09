<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gama de la marca: cada marca es Standard o Premium. Las existentes quedan como Standard.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'tier')) {
                $table->string('tier', 20)->default('standard');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'tier')) {
                $table->dropColumn('tier');
            }
        });
    }
};
