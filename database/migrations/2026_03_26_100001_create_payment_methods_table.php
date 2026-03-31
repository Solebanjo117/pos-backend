<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // Display name: "Efectivo", "Yappy"
            $table->string('code')->unique(); // Internal code: "cash", "yappy"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);        // System methods can't be archived
            $table->boolean('requires_reference')->default(false); // Needs reference number
            $table->boolean('requires_cash_count')->default(false); // Count in opening/closing
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
