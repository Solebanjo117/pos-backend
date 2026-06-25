<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_pricing')) {
            return;
        }

        $hasCostBase = Schema::hasColumn('product_pricing', 'cost_base');
        $hasCostMiscellaneous = Schema::hasColumn('product_pricing', 'cost_miscellaneous');

        if (! $hasCostBase || ! $hasCostMiscellaneous) {
            Schema::table('product_pricing', function (Blueprint $table) use ($hasCostBase, $hasCostMiscellaneous) {
                if (! $hasCostBase) {
                    $table->decimal('cost_base', 10, 2)->nullable()->after('cost');
                }

                if (! $hasCostMiscellaneous) {
                    $table->decimal('cost_miscellaneous', 10, 2)->default(0)->after('cost_tax_rate');
                }
            });
        }

        DB::table('product_pricing')
            ->whereNull('cost_miscellaneous')
            ->update(['cost_miscellaneous' => 0]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_pricing')) {
            return;
        }

        $hasCostBase = Schema::hasColumn('product_pricing', 'cost_base');
        $hasCostMiscellaneous = Schema::hasColumn('product_pricing', 'cost_miscellaneous');

        if (! $hasCostBase && ! $hasCostMiscellaneous) {
            return;
        }

        Schema::table('product_pricing', function (Blueprint $table) use ($hasCostBase, $hasCostMiscellaneous) {
            if ($hasCostBase) {
                $table->dropColumn('cost_base');
            }

            if ($hasCostMiscellaneous) {
                $table->dropColumn('cost_miscellaneous');
            }
        });
    }
};
