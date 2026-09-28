<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_cash_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->unique()->constrained();
            $table->decimal('opening_balance', 14, 2);
            $table->timestamp('cutoff_at');
            $table->unsignedBigInteger('starting_entry_id')->default(0);
            $table->foreignId('confirmed_by')->constrained('users');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_cash_balances');
    }
};
