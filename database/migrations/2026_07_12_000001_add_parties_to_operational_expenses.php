<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_expense_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('name_key', 160)->unique();
            $table->string('type', 20)->default('individual');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('operational_expense_beneficiaries')->nullOnDelete();
            $table->index(['customer_id', 'expense_date']);
            $table->index(['beneficiary_id', 'expense_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE operational_expenses
                ADD CONSTRAINT operational_expenses_single_party_check
                CHECK (num_nonnulls(customer_id, supplier_id, beneficiary_id) <= 1)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operational_expenses DROP CONSTRAINT IF EXISTS operational_expenses_single_party_check');
        }

        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->dropIndex(['beneficiary_id', 'expense_date']);
            $table->dropIndex(['customer_id', 'expense_date']);
            $table->dropConstrainedForeignId('beneficiary_id');
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('operational_expense_beneficiaries');
    }
};
