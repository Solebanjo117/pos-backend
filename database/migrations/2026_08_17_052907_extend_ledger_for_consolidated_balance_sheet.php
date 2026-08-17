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
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->string('system_key', 80)->nullable()->unique()->after('code');
            $table->string('report_group', 80)->nullable()->index()->after('type');
            $table->string('normal_balance', 10)->nullable()->after('report_group');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('posting_key', 190)->nullable()->unique()->after('source_id');
            $table->foreignId('reverses_entry_id')->nullable()->after('posting_key')->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable()->after('memo');
        });

        $accounts = [
            ['code' => '1100', 'name' => 'Efectivo', 'type' => 'asset', 'system_key' => 'cash', 'report_group' => 'cash', 'normal_balance' => 'debit'],
            ['code' => '1110', 'name' => 'Banco', 'type' => 'asset', 'system_key' => 'bank', 'report_group' => 'bank', 'normal_balance' => 'debit'],
            ['code' => '1200', 'name' => 'Cuentas por cobrar', 'type' => 'asset', 'system_key' => 'accounts_receivable', 'report_group' => 'accounts_receivable', 'normal_balance' => 'debit'],
            ['code' => '1300', 'name' => 'Inventario', 'type' => 'asset', 'system_key' => 'inventory', 'report_group' => 'inventory', 'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Cuentas por pagar', 'type' => 'liability', 'system_key' => 'accounts_payable', 'report_group' => 'accounts_payable', 'normal_balance' => 'credit'],
            ['code' => '2110', 'name' => 'Saldos a favor de clientes', 'type' => 'liability', 'system_key' => 'customer_credits', 'report_group' => 'customer_credits', 'normal_balance' => 'credit'],
            ['code' => '2120', 'name' => 'Reembolsos pendientes', 'type' => 'liability', 'system_key' => 'pending_refunds', 'report_group' => 'pending_refunds', 'normal_balance' => 'credit'],
            ['code' => '2200', 'name' => 'ITBMS por pagar', 'type' => 'liability', 'system_key' => 'sales_tax_payable', 'report_group' => 'other_liabilities', 'normal_balance' => 'credit'],
            ['code' => '3000', 'name' => 'Capital aportado', 'type' => 'equity', 'system_key' => 'contributed_capital', 'report_group' => 'contributed_capital', 'normal_balance' => 'credit'],
            ['code' => '3100', 'name' => 'Resultados acumulados', 'type' => 'equity', 'system_key' => 'retained_earnings', 'report_group' => 'retained_earnings', 'normal_balance' => 'credit'],
            ['code' => '4000', 'name' => 'Ventas', 'type' => 'income', 'system_key' => 'sales_revenue', 'report_group' => 'income', 'normal_balance' => 'credit'],
            ['code' => '4010', 'name' => 'Descuentos y devoluciones sobre ventas', 'type' => 'income', 'system_key' => 'sales_returns', 'report_group' => 'contra_income', 'normal_balance' => 'debit'],
            ['code' => '5000', 'name' => 'Gastos operativos', 'type' => 'expense', 'system_key' => 'operational_expenses', 'report_group' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '5100', 'name' => 'Costo de ventas', 'type' => 'expense', 'system_key' => 'cost_of_goods_sold', 'report_group' => 'cost_of_goods_sold', 'normal_balance' => 'debit'],
        ];

        foreach ($accounts as $account) {
            $existing = DB::table('ledger_accounts')->where('code', $account['code'])->first();

            if ($existing) {
                DB::table('ledger_accounts')->where('id', $existing->id)->update([
                    'system_key' => $account['system_key'],
                    'report_group' => $account['report_group'],
                    'normal_balance' => $account['normal_balance'],
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('ledger_accounts')->insert([
                ...$account,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_entry_id');
            $table->dropUnique(['posting_key']);
            $table->dropColumn(['posting_key', 'metadata']);
        });

        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropUnique(['system_key']);
            $table->dropIndex(['report_group']);
            $table->dropColumn(['system_key', 'report_group', 'normal_balance']);
        });
    }
};
