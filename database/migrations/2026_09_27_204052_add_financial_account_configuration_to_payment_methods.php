<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->boolean('requires_financial_account')->default(true);
            $table->foreignId('default_financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_financial_account_id');
            $table->dropColumn('requires_financial_account');
        });
    }
};
