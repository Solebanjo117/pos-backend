<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Add pricing_formula to categories table (per-category override).
     * The global pricing_formula is stored in the settings table as a key-value entry.
     */
    public function up(): void
    {
        // Per-category formula override
        Schema::table('categories', function (Blueprint $table) {
            $table->text('pricing_formula')->nullable()->after('default_attributes');
        });

        // Seed the global pricing_formula setting (empty by default)
        Setting::set('pricing_formula', '', 'string', 'pricing');
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('pricing_formula');
        });

        Setting::where('key', 'pricing_formula')->delete();
    }
};
