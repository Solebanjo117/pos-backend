<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('code', 255);
            $table->string('normalized_code', 255);
            $table->string('type', 32)->default('manual');
            $table->string('status', 32)->default('active');
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'variant_id']);
            $table->index(['normalized_code', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku_base')->nullable()->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });

        DB::statement("CREATE UNIQUE INDEX product_codes_active_normalized_unique ON product_codes (normalized_code) WHERE status = 'active' AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX product_codes_variant_primary_unique ON product_codes (variant_id) WHERE variant_id IS NOT NULL AND is_primary = true AND status = 'active' AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX product_codes_product_primary_unique ON product_codes (product_id) WHERE variant_id IS NULL AND is_primary = true AND status = 'active' AND deleted_at IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_codes_product_primary_unique');
        DB::statement('DROP INDEX IF EXISTS product_codes_variant_primary_unique');
        DB::statement('DROP INDEX IF EXISTS product_codes_active_normalized_unique');
        Schema::dropIfExists('product_codes');
    }
};
