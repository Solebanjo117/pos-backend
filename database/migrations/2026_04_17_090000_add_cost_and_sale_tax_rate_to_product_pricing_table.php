<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasCostTaxRate = Schema::hasColumn('product_pricing', 'cost_tax_rate');
        $hasSaleTaxRate = Schema::hasColumn('product_pricing', 'sale_tax_rate');

        if (! $hasCostTaxRate || ! $hasSaleTaxRate) {
            Schema::table('product_pricing', function (Blueprint $table) use ($hasCostTaxRate, $hasSaleTaxRate) {
                if (! $hasCostTaxRate) {
                    $table->decimal('cost_tax_rate', 5, 2)->nullable();
                }

                if (! $hasSaleTaxRate) {
                    $table->decimal('sale_tax_rate', 5, 2)->nullable();
                }
            });
        }

        DB::table('product_pricing')
            ->whereNull('sale_tax_rate')
            ->update(['sale_tax_rate' => DB::raw('tax_rate')]);

        DB::table('product_pricing')
            ->whereNull('cost_tax_rate')
            ->update(['cost_tax_rate' => DB::raw('COALESCE(tax_rate, sale_tax_rate, 0)')]);

        DB::table('product_pricing')
            ->whereNull('tax_rate')
            ->update(['tax_rate' => DB::raw('sale_tax_rate')]);
    }

    public function down(): void
    {
        $hasCostTaxRate = Schema::hasColumn('product_pricing', 'cost_tax_rate');
        $hasSaleTaxRate = Schema::hasColumn('product_pricing', 'sale_tax_rate');

        if ($hasCostTaxRate || $hasSaleTaxRate) {
            Schema::table('product_pricing', function (Blueprint $table) use ($hasCostTaxRate, $hasSaleTaxRate) {
                if ($hasCostTaxRate) {
                    $table->dropColumn('cost_tax_rate');
                }

                if ($hasSaleTaxRate) {
                    $table->dropColumn('sale_tax_rate');
                }
            });
        }
    }
};
