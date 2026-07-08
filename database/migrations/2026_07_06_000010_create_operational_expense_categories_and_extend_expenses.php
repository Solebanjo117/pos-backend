<?php

use App\Models\OperationalExpense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subcategory')->nullable();
            $table->foreignId('default_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('expense_kind', 40)->default('operational');
            $table->boolean('affects_cash_flow')->default(true);
            $table->boolean('affects_income_statement')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        $rows = collect(OperationalExpense::CATEGORIES)
            ->map(function (string $name, string $slug) use ($now): array {
                return [
                    'name' => $name,
                    'slug' => Str::slug($slug),
                    'expense_kind' => 'operational',
                    'affects_cash_flow' => true,
                    'affects_income_statement' => true,
                    'is_active' => true,
                    'sort_order' => array_search($slug, array_keys(OperationalExpense::CATEGORIES), true) ?: 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();

        DB::table('operational_expense_categories')->insert($rows);

        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->foreignId('category_id')
                ->nullable()
                ->after('category')
                ->constrained('operational_expense_categories')
                ->nullOnDelete();
            $table->string('subcategory')->nullable()->after('category_id');
            $table->foreignId('supplier_id')->nullable()->after('subcategory')->constrained('suppliers')->nullOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->after('supplier_id')->constrained('ledger_accounts')->nullOnDelete();
            $table->string('recurrence', 30)->default('one_time')->after('payment_method');
            $table->string('status', 30)->default('paid')->after('recurrence');
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->boolean('affects_cash_flow')->default(true)->after('paid_at');
            $table->boolean('affects_income_statement')->default(true)->after('affects_cash_flow');
            $table->string('expense_kind', 40)->default('operational')->after('affects_income_statement');

            $table->index(['status', 'expense_date']);
            $table->index(['category_id', 'expense_date']);
            $table->index(['supplier_id', 'expense_date']);
        });

        DB::table('operational_expenses')->update([
            'status' => 'paid',
            'recurrence' => 'one_time',
            'paid_at' => DB::raw('created_at'),
            'affects_cash_flow' => true,
            'affects_income_statement' => true,
            'expense_kind' => 'operational',
        ]);

        if (
            Schema::hasTable('operational_expense_categories')
            && Schema::hasTable('operational_expenses')
            && Schema::hasColumn('operational_expenses', 'category_id')
        ) {
            $categories = DB::table('operational_expense_categories')
                ->pluck('id', 'slug');

            foreach ($categories as $slug => $categoryId) {
                DB::table('operational_expenses')
                    ->whereNull('category_id')
                    ->where('category', $slug)
                    ->update(['category_id' => $categoryId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table): void {
            $table->dropIndex(['status', 'expense_date']);
            $table->dropIndex(['category_id', 'expense_date']);
            $table->dropIndex(['supplier_id', 'expense_date']);
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropConstrainedForeignId('ledger_account_id');
            $table->dropColumn([
                'subcategory',
                'recurrence',
                'status',
                'paid_at',
                'affects_cash_flow',
                'affects_income_statement',
                'expense_kind',
            ]);
        });

        Schema::dropIfExists('operational_expense_categories');
    }
};
