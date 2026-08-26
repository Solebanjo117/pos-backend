<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->foreignId('financial_account_id')
                ->nullable()
                ->after('refund_method')
                ->constrained('financial_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_account_id');
        });
    }
};
