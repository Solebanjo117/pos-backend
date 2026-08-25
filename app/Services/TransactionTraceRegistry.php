<?php

namespace App\Services;

use App\Models\AccountLedgerEntry;
use App\Models\AccountPaymentBatch;
use App\Models\CashierQueueEntry;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseBeneficiary;
use App\Models\OperationalExpenseCategory;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesCreditNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesQuote;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransactionTraceRegistry
{
    /** @var array<string, array{model: class-string<Model>, roles: list<string>}> */
    private const DEFINITIONS = [
        'sales_order' => ['model' => SalesOrder::class, 'roles' => ['admin', 'manager', 'buyer', 'seller', 'assistant', 'cashier']],
        'sales_quote' => ['model' => SalesQuote::class, 'roles' => ['admin', 'manager', 'buyer', 'seller', 'assistant', 'cashier']],
        'sales_return' => ['model' => SalesReturn::class, 'roles' => ['admin', 'manager', 'seller', 'cashier']],
        'sales_credit_note' => ['model' => SalesCreditNote::class, 'roles' => ['admin', 'manager', 'cashier']],
        'purchase_order' => ['model' => PurchaseOrder::class, 'roles' => ['admin', 'manager', 'buyer']],
        'operational_expense' => ['model' => OperationalExpense::class, 'roles' => ['admin', 'manager']],
        'operational_expense_beneficiary' => ['model' => OperationalExpenseBeneficiary::class, 'roles' => ['admin', 'manager']],
        'operational_expense_category' => ['model' => OperationalExpenseCategory::class, 'roles' => ['admin', 'manager']],
        'cashier_queue_entry' => ['model' => CashierQueueEntry::class, 'roles' => ['admin', 'manager', 'cashier']],
        'pos_session' => ['model' => PosSession::class, 'roles' => ['admin', 'manager', 'cashier']],
        'sales_order_payment' => ['model' => SalesOrderPayment::class, 'roles' => ['admin', 'manager', 'seller', 'cashier']],
        'purchase_order_payment' => ['model' => PurchaseOrderPayment::class, 'roles' => ['admin', 'manager', 'buyer']],
        'inventory_movement' => ['model' => InventoryMovement::class, 'roles' => ['admin', 'manager', 'buyer', 'assistant']],
        'inventory_transfer' => ['model' => InventoryTransfer::class, 'roles' => ['admin', 'manager', 'buyer', 'assistant']],
        'inventory_lot' => ['model' => InventoryLot::class, 'roles' => ['admin', 'manager', 'buyer', 'assistant']],
        'product' => ['model' => Product::class, 'roles' => User::VALID_ROLES],
        'product_variant' => ['model' => ProductVariant::class, 'roles' => User::VALID_ROLES],
        'warehouse' => ['model' => Warehouse::class, 'roles' => User::VALID_ROLES],
        'cash_movement' => ['model' => CashMovement::class, 'roles' => ['admin', 'manager', 'cashier']],
        'customer_transaction' => ['model' => CustomerTransaction::class, 'roles' => ['admin', 'manager', 'seller', 'cashier']],
        'account_ledger_entry' => ['model' => AccountLedgerEntry::class, 'roles' => ['admin', 'manager', 'buyer', 'seller', 'cashier']],
        'account_payment_batch' => ['model' => AccountPaymentBatch::class, 'roles' => ['admin', 'manager', 'buyer', 'seller', 'cashier']],
        'customer' => ['model' => Customer::class, 'roles' => ['admin', 'manager', 'seller', 'cashier']],
        'supplier' => ['model' => Supplier::class, 'roles' => ['admin', 'manager', 'buyer']],
        'user' => ['model' => User::class, 'roles' => ['admin']],
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'sale' => 'sales_order',
        'return' => 'sales_return',
        'transfer' => 'inventory_transfer',
        'credit_note' => 'sales_credit_note',
        'purchase' => 'purchase_order',
        'operational_expense_payment' => 'operational_expense',
        'operational_expense_reversal' => 'operational_expense',
        SalesOrder::class => 'sales_order',
        SalesQuote::class => 'sales_quote',
        SalesReturn::class => 'sales_return',
        OperationalExpense::class => 'operational_expense',
        OperationalExpenseBeneficiary::class => 'operational_expense_beneficiary',
        OperationalExpenseCategory::class => 'operational_expense_category',
    ];

    /** @return list<string> */
    public function supportedTypes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function normalize(string $type): ?string
    {
        $normalized = self::ALIASES[$type] ?? Str::snake(trim($type));

        return isset(self::DEFINITIONS[$normalized]) ? $normalized : null;
    }

    /** @return class-string<Model>|null */
    public function modelClass(string $type): ?string
    {
        return self::DEFINITIONS[$type]['model'] ?? null;
    }

    /** @return list<string> */
    public function roles(string $type): array
    {
        return self::DEFINITIONS[$type]['roles'] ?? [];
    }

    public function typeFor(Model $model): ?string
    {
        foreach (self::DEFINITIONS as $type => $definition) {
            if ($model instanceof $definition['model']) {
                return $type;
            }
        }

        return null;
    }
}
