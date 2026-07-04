<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('vendor_external_id', 100)->nullable()->after('name');
            $table->unique('vendor_external_id', 'suppliers_vendor_external_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_vendor_external_id_unique');
            $table->dropColumn('vendor_external_id');
        });
    }
};
