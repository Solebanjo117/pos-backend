<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_manage_financial_adjustments')->default(false)->after('is_active');
        });

        \Illuminate\Support\Facades\DB::table('users')
            ->where('role', 'admin')
            ->update(['can_manage_financial_adjustments' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_manage_financial_adjustments');
        });
    }
};
