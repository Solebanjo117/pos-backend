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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TransactionTraceService
{
    public function __construct(private readonly TransactionTraceRegistry $registry) {}

    /** @return list<string> */
    public function supportedTypes(): array
    {
        return $this->registry->supportedTypes();
    }

    /**
     * Build the same authorized node used by the trace endpoint for another API.
     *
     * @return array<string, mixed>
     */
    public function reference(string $type, Model $model, User $user, ?string $relation = null): array
    {
        $normalized = $this->registry->normalize($type);

        return $this->node($normalized, $model, $user, $relation);
    }

    /** @return array<string, mixed> */
    public function get(string $type, int $id, User $user, int $page = 1, int $perPage = 25): array
    {
        $type = $this->normalizeType($type);
        $model = $this->resolve($type, $id);

        if (! $this->canView($user, $type, $model)) {
            throw new AccessDeniedHttpException('No tiene permiso para consultar este documento.');
        }

        $relationships = $this->relationships($type, $model, $user);
        $events = $this->timeline($type, $model, $relationships, $user)
            ->sortBy(fn (array $event) => $event['occurred_at'] ?? '')
            ->values();

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $total = $events->count();
        $rootNode = $this->node($type, $model, $user, true);

        return [
            'root' => $rootNode,
            'details' => $this->details($type, $model),
            'relationships' => $relationships->values()->all(),
            'timeline' => $events->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'breadcrumbs' => [$rootNode],
            'actions' => $rootNode['href'] ? [[
                'key' => 'open',
                'label' => 'Abrir detalle completo',
                'href' => $rootNode['href'],
                'allowed' => true,
            ]] : [],
            'context' => ['type' => $type, 'id' => (int) $model->getKey()],
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function details(string $type, Model $model): ?array
    {
        if ($type !== 'inventory_movement') {
            return null;
        }

        /** @var InventoryMovement $model */
        $model->loadMissing([
            'product:id,name,sku_base',
            'variant:id,sku',
            'warehouse:id,name,code',
            'destinationWarehouse:id,name,code',
            'user:id,name',
            'lotAllocations.lot',
            'sourceLots',
        ]);
        $allocations = $model->lotAllocations;
        $totalCost = round((float) $allocations->sum('total_cost'), 2);
        if ($totalCost === 0.0 && $model->sourceLots->isNotEmpty()) {
            $totalCost = round((float) $model->sourceLots->sum(
                fn ($lot) => (float) $lot->landed_unit_cost * (int) $lot->received_quantity
            ), 2);
        }
        $unitCost = (int) $model->quantity > 0 ? round($totalCost / (int) $model->quantity, 4) : 0.0;

        return [
            'movement_type' => $model->movement_type,
            'movement_group_uuid' => $model->movement_group_uuid,
            'business_datetime' => $model->business_datetime?->toISOString(),
            'created_at' => $model->created_at?->toISOString(),
            'user' => $model->user ? ['id' => $model->user->id, 'name' => $model->user->name] : null,
            'warehouse' => $model->warehouse ? ['id' => $model->warehouse->id, 'name' => $model->warehouse->name, 'code' => $model->warehouse->code] : null,
            'destination_warehouse' => $model->destinationWarehouse ? ['id' => $model->destinationWarehouse->id, 'name' => $model->destinationWarehouse->name, 'code' => $model->destinationWarehouse->code] : null,
            'product' => $model->product ? ['id' => $model->product->id, 'name' => $model->product->name, 'sku' => $model->product->sku_base, 'href' => '/products/'.$model->product->id] : null,
            'variant' => $model->variant ? ['id' => $model->variant->id, 'sku' => $model->variant->sku] : null,
            'quantity_before' => $model->quantity_before,
            'quantity' => (int) $model->quantity,
            'quantity_after' => $model->quantity_after,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'reason' => $model->reason,
            'notes' => $model->notes,
            'fifo_lots' => $allocations->map(fn ($allocation) => [
                'id' => $allocation->inventory_lot_id,
                'quantity' => (int) $allocation->quantity,
                'unit_cost' => (float) $allocation->unit_cost,
                'total_cost' => (float) $allocation->total_cost,
                'batch_number' => $allocation->lot?->batch_number,
            ])->values()->all(),
        ];
    }

    private function normalizeType(string $type): string
    {
        $type = $this->registry->normalize($type);
        abort_unless($type !== null, 404, 'Tipo de documento no soportado.');

        return $type;
    }

    private function resolve(string $type, int $id): Model
    {
        $modelClass = $this->registry->modelClass($type);
        abort_unless($modelClass !== null, 404, 'Tipo de documento no soportado.');

        return $modelClass::query()->findOrFail($id);
    }

    private function canView(User $user, string $type, Model $model): bool
    {
        if (! in_array($user->role, $this->registry->roles($type), true)) {
            return false;
        }

        if ($type === 'inventory_transfer' && ! $user->isAdmin()) {
            return $user->hasWarehouseAccess((int) $model->from_warehouse_id)
                || $user->hasWarehouseAccess((int) $model->to_warehouse_id);
        }

        if ($type === 'account_payment_batch') {
            /** @var AccountPaymentBatch $model */
            $allowedRoles = $model->account_type === 'supplier'
                ? ['admin', 'manager', 'buyer']
                : ['admin', 'manager', 'seller', 'cashier'];

            if (! in_array($user->role, $allowedRoles, true)) {
                return false;
            }
        }

        $warehouseIds = $this->warehouseIds($type, $model);

        return $warehouseIds->isEmpty()
            || $warehouseIds->every(fn (int $warehouseId): bool => $user->hasWarehouseAccess($warehouseId));
    }

    /** @return Collection<int, int> */
    private function warehouseIds(string $type, Model $model): Collection
    {
        $direct = $model->getAttribute('warehouse_id');
        if ($direct !== null) {
            return collect([(int) $direct]);
        }

        $warehouseIds = match ($type) {
            'warehouse' => [(int) $model->id],
            'sales_order' => $model->items()
                ->whereNotNull('warehouse_id')
                ->pluck('warehouse_id')
                ->push($model->posSession?->warehouse_id)
                ->all(),
            'sales_order_payment' => $model->salesOrder
                ? $this->warehouseIds('sales_order', $model->salesOrder)->all()
                : [],
            'purchase_order_payment' => [$model->purchaseOrder?->warehouse_id],
            'cash_movement' => [$model->posSession?->warehouse_id],
            'account_payment_batch' => [$model->posSession?->warehouse_id],
            default => [],
        };

        return collect($warehouseIds)
            ->filter(fn ($warehouseId): bool => $warehouseId !== null)
            ->map(fn ($warehouseId): int => (int) $warehouseId)
            ->unique()
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function relationships(string $type, Model $model, User $user): Collection
    {
        $nodes = collect();
        $add = function (?Model $related, string $relatedType, string $relation) use ($nodes, $user): void {
            if ($related) {
                $nodes->push($this->node($relatedType, $related, $user, false, $relation));
            }
        };

        if ($type === 'sales_order') {
            /** @var SalesOrder $model */
            $model->loadMissing(['customer', 'user', 'payments.creator', 'returns.creditNote', 'posSession']);
            $add($model->customer, 'customer', 'customer');
            $add($model->user, 'user', 'seller');
            $add(SalesQuote::query()->where('converted_order_id', $model->id)->first(), 'sales_quote', 'source_quote');
            $add($model->posSession, 'pos_session', 'cash_session');
            $model->payments->each(fn (SalesOrderPayment $payment) => $add($payment, 'sales_order_payment', 'payment'));
            $model->returns->each(function (SalesReturn $return) use ($add): void {
                $add($return, 'sales_return', 'return');
                $add($return->creditNote, 'sales_credit_note', 'credit_note');
            });
            $this->inventoryFor('sales_order', $model->id)->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
            CustomerTransaction::query()->where('reference_type', 'sales_order')->where('reference_id', $model->id)->get()
                ->each(fn (CustomerTransaction $entry) => $add($entry, 'customer_transaction', 'receivable'));
            AccountLedgerEntry::query()->where('document_type', 'sales_order')->where('document_id', $model->id)->get()
                ->each(fn (AccountLedgerEntry $entry) => $add($entry, 'account_ledger_entry', 'receivable_ledger'));
            $model->items()->with(['product', 'variant'])->get()->each(function ($item) use ($add): void {
                $add($item->product, 'product', 'item');
                $add($item->variant, 'product_variant', 'variant');
            });
        } elseif ($type === 'sales_return') {
            /** @var SalesReturn $model */
            $model->loadMissing(['salesOrder', 'customer', 'creditNote']);
            $add($model->salesOrder, 'sales_order', 'source_invoice');
            $add($model->customer, 'customer', 'customer');
            $add($model->creditNote, 'sales_credit_note', 'credit_note');
            $this->queueFor($model)->each(fn (CashierQueueEntry $entry) => $add($entry, 'cashier_queue_entry', 'cashier_queue'));
            SalesOrderPayment::query()->where('sales_return_id', $model->id)->get()
                ->each(fn (SalesOrderPayment $payment) => $add($payment, 'sales_order_payment', 'refund'));
            $this->inventoryFor('sales_return', $model->id)->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
            CashMovement::query()->where('source_type', 'sales_return')->where('source_id', $model->id)->get()
                ->each(fn (CashMovement $movement) => $add($movement, 'cash_movement', 'cash_movement'));
            CustomerTransaction::query()->where('reference_type', 'sales_return')->where('reference_id', $model->id)->get()
                ->each(fn (CustomerTransaction $entry) => $add($entry, 'customer_transaction', 'receivable_adjustment'));
            AccountLedgerEntry::query()->where('document_type', 'sales_return')->where('document_id', $model->id)->get()
                ->each(fn (AccountLedgerEntry $entry) => $add($entry, 'account_ledger_entry', 'receivable_ledger'));
        } elseif ($type === 'sales_credit_note') {
            /** @var SalesCreditNote $model */
            $model->loadMissing(['salesReturn', 'salesOrder', 'customer']);
            $add($model->salesReturn, 'sales_return', 'return');
            $add($model->salesOrder, 'sales_order', 'invoice');
            $add($model->customer, 'customer', 'customer');
        } elseif ($type === 'sales_quote') {
            /** @var SalesQuote $model */
            $model->loadMissing(['customer', 'convertedOrder', 'posSession']);
            $add($model->customer, 'customer', 'customer');
            $add($model->convertedOrder, 'sales_order', 'converted_invoice');
            $add($model->posSession, 'pos_session', 'cash_session');
            $this->queueFor($model)->each(fn (CashierQueueEntry $entry) => $add($entry, 'cashier_queue_entry', 'cashier_queue'));
        } elseif ($type === 'purchase_order') {
            /** @var PurchaseOrder $model */
            $model->loadMissing(['supplier', 'payments.cashMovement', 'payments.posSession']);
            $add($model->supplier, 'supplier', 'supplier');
            $model->payments->each(function (PurchaseOrderPayment $payment) use ($add): void {
                $add($payment, 'purchase_order_payment', 'payment');
                $add($payment->cashMovement, 'cash_movement', 'cash_movement');
                $add($payment->posSession, 'pos_session', 'cash_session');
            });
            $this->inventoryFor('purchase_order', $model->id)->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
            AccountLedgerEntry::query()->where('document_type', 'purchase_order')->where('document_id', $model->id)->get()
                ->each(fn (AccountLedgerEntry $entry) => $add($entry, 'account_ledger_entry', 'payable'));
            $model->items()->with(['product', 'variant'])->get()->each(function ($item) use ($add): void {
                $add($item->product, 'product', 'item');
                $add($item->variant, 'product_variant', 'variant');
            });
        } elseif ($type === 'operational_expense') {
            /** @var OperationalExpense $model */
            $model->loadMissing(['supplier', 'customer', 'beneficiary', 'categoryRecord', 'cashMovement', 'posSession']);
            $add($model->customer, 'customer', 'beneficiary');
            $add($model->supplier, 'supplier', 'beneficiary');
            $add($model->beneficiary, 'operational_expense_beneficiary', 'beneficiary');
            $add($model->categoryRecord, 'operational_expense_category', 'category');
            $add($model->cashMovement, 'cash_movement', 'cash_movement');
            $add($model->posSession, 'pos_session', 'cash_session');
            $this->queueFor($model)->each(fn (CashierQueueEntry $entry) => $add($entry, 'cashier_queue_entry', 'cashier_queue'));
            AccountLedgerEntry::query()->where('document_type', 'operational_expense')->where('document_id', $model->id)->get()
                ->each(fn (AccountLedgerEntry $entry) => $add($entry, 'account_ledger_entry', 'payable'));
        } elseif ($type === 'cashier_queue_entry') {
            /** @var CashierQueueEntry $model */
            $model->loadMissing(['source', 'warehouse', 'opener', 'completer']);
            $sourceType = $this->typeForModel($model->source);
            if ($sourceType && $model->source) {
                $add($model->source, $sourceType, 'source');
            }
        } elseif ($type === 'pos_session') {
            /** @var PosSession $model */
            $model->salesOrders()->limit(100)->get()->each(fn (SalesOrder $order) => $add($order, 'sales_order', 'sale'));
            $model->cashMovements()->limit(100)->get()->each(fn (CashMovement $movement) => $add($movement, 'cash_movement', 'cash_movement'));
            $model->purchaseOrderPayments()->limit(100)->get()->each(fn (PurchaseOrderPayment $payment) => $add($payment, 'purchase_order_payment', 'supplier_payment'));
            $model->accountPaymentBatches()->limit(100)->get()->each(fn (AccountPaymentBatch $payment) => $add($payment, 'account_payment_batch', 'account_payment'));
            OperationalExpense::query()->where('pos_session_id', $model->id)->limit(100)->get()
                ->each(fn (OperationalExpense $expense) => $add($expense, 'operational_expense', 'expense'));
        } elseif ($type === 'account_payment_batch') {
            /** @var AccountPaymentBatch $model */
            $model->loadMissing(['posSession', 'cashMovement', 'ledgerEntries', 'applications']);
            $account = $model->account_type === 'supplier'
                ? Supplier::query()->find($model->account_id)
                : Customer::query()->find($model->account_id);
            $add($account, $model->account_type === 'supplier' ? 'supplier' : 'customer', 'account');
            $add($model->posSession, 'pos_session', 'cash_session');
            $add($model->cashMovement, 'cash_movement', 'cash_movement');
            $model->ledgerEntries->each(fn (AccountLedgerEntry $entry) => $add($entry, 'account_ledger_entry', 'account_ledger'));
            $model->applications->each(function ($application) use ($add): void {
                $related = $this->resolveReference($application->document_type, $application->document_id);
                if ($related) {
                    $add($related['model'], $related['type'], 'applied_document');
                }
            });
        } elseif ($type === 'sales_order_payment') {
            /** @var SalesOrderPayment $model */
            $model->loadMissing(['salesOrder.posSession', 'salesReturn']);
            $add($model->salesOrder, 'sales_order', 'invoice');
            $add($model->salesReturn, 'sales_return', 'return');
            $add($model->salesOrder?->posSession, 'pos_session', 'cash_session');
        } elseif ($type === 'purchase_order_payment') {
            /** @var PurchaseOrderPayment $model */
            $model->loadMissing(['purchaseOrder', 'cashMovement', 'posSession']);
            $add($model->purchaseOrder, 'purchase_order', 'purchase_order');
            $add($model->cashMovement, 'cash_movement', 'cash_movement');
            $add($model->posSession, 'pos_session', 'cash_session');
        } elseif ($type === 'inventory_movement') {
            /** @var InventoryMovement $model */
            $model->loadMissing(['product', 'variant', 'warehouse', 'destinationWarehouse', 'user', 'lotAllocations.lot']);
            $add($model->product, 'product', 'product');
            $add($model->variant, 'product_variant', 'variant');
            $add($model->warehouse, 'warehouse', 'source_warehouse');
            $add($model->destinationWarehouse, 'warehouse', 'destination_warehouse');
            $add($model->user, 'user', 'user');
            $model->lotAllocations->each(fn ($allocation) => $add($allocation->lot, 'inventory_lot', 'fifo_lot'));
            $related = $this->resolveReference($model->reference_type, $model->reference_id);
            if ($related) {
                $add($related['model'], $related['type'], 'source_document');
            }
            $this->relatedInventory($model)->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'related_movement'));
        } elseif ($type === 'inventory_transfer') {
            /** @var InventoryTransfer $model */
            $this->inventoryFor('transfer', $model->id)->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
        } elseif ($type === 'cash_movement') {
            /** @var CashMovement $model */
            $model->loadMissing('posSession');
            $add($model->posSession, 'pos_session', 'cash_session');
            $related = $this->resolveReference($model->source_type, $model->source_id);
            if ($related) {
                $add($related['model'], $related['type'], 'source_document');
            }
        } elseif ($type === 'customer_transaction') {
            /** @var CustomerTransaction $model */
            $model->loadMissing('customer');
            $add($model->customer, 'customer', 'customer');
            $related = $this->resolveReference($model->reference_type, $model->reference_id);
            if ($related) {
                $add($related['model'], $related['type'], 'source_document');
            }
        } elseif ($type === 'account_ledger_entry') {
            /** @var AccountLedgerEntry $model */
            $related = $this->resolveReference($model->document_type, $model->document_id);
            if ($related) {
                $add($related['model'], $related['type'], 'source_document');
            }
        } elseif ($type === 'customer') {
            /** @var Customer $model */
            SalesOrder::query()->where('customer_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (SalesOrder $order) => $add($order, 'sales_order', 'sale'));
            SalesReturn::query()->where('customer_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (SalesReturn $return) => $add($return, 'sales_return', 'return'));
            CustomerTransaction::query()->where('customer_id', $model->id)->latest('transaction_date')->limit(50)->get()
                ->each(fn (CustomerTransaction $entry) => $add($entry, 'customer_transaction', 'receivable'));
            OperationalExpense::query()->where('customer_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (OperationalExpense $expense) => $add($expense, 'operational_expense', 'expense'));
        } elseif ($type === 'supplier') {
            /** @var Supplier $model */
            PurchaseOrder::query()->where('supplier_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (PurchaseOrder $order) => $add($order, 'purchase_order', 'purchase_order'));
            OperationalExpense::query()->where('supplier_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (OperationalExpense $expense) => $add($expense, 'operational_expense', 'expense'));
        } elseif ($type === 'operational_expense_beneficiary') {
            /** @var OperationalExpenseBeneficiary $model */
            $model->expenses()->latest()->limit(100)->get()
                ->each(fn (OperationalExpense $expense) => $add($expense, 'operational_expense', 'expense'));
        } elseif ($type === 'operational_expense_category') {
            /** @var OperationalExpenseCategory $model */
            $model->expenses()->latest()->limit(100)->get()
                ->each(fn (OperationalExpense $expense) => $add($expense, 'operational_expense', 'expense'));
        } elseif ($type === 'product') {
            /** @var Product $model */
            $model->variants()->limit(100)->get()
                ->each(fn (ProductVariant $variant) => $add($variant, 'product_variant', 'variant'));
            InventoryMovement::query()->where('product_id', $model->id)->latest()->limit(100)->get()
                ->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
            $model->suppliers()->limit(50)->get()
                ->each(fn (Supplier $supplier) => $add($supplier, 'supplier', 'supplier'));
        } elseif ($type === 'product_variant') {
            /** @var ProductVariant $model */
            $model->loadMissing('product');
            $add($model->product, 'product', 'product');
            $model->inventoryLots()->latest('received_at')->limit(100)->get()
                ->each(fn (InventoryLot $lot) => $add($lot, 'inventory_lot', 'fifo_lot'));
            InventoryMovement::query()->where('variant_id', $model->id)->latest()->limit(100)->get()
                ->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
        } elseif ($type === 'inventory_lot') {
            /** @var InventoryLot $model */
            $model->loadMissing(['product', 'variant', 'warehouse', 'sourceMovement', 'allocations.movement']);
            $add($model->product, 'product', 'product');
            $add($model->variant, 'product_variant', 'variant');
            $add($model->warehouse, 'warehouse', 'warehouse');
            $add($model->sourceMovement, 'inventory_movement', 'source_movement');
            $model->allocations->each(fn ($allocation) => $add($allocation->movement, 'inventory_movement', 'consumption'));
        } elseif ($type === 'warehouse') {
            /** @var Warehouse $model */
            InventoryMovement::query()
                ->where(fn ($query) => $query->where('warehouse_id', $model->id)->orWhere('destination_warehouse_id', $model->id))
                ->latest()->limit(100)->get()
                ->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
            PosSession::query()->where('warehouse_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (PosSession $session) => $add($session, 'pos_session', 'cash_session'));
        } elseif ($type === 'user') {
            /** @var User $model */
            SalesOrder::query()->where('user_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (SalesOrder $order) => $add($order, 'sales_order', 'sale'));
            SalesOrderPayment::query()->where('created_by', $model->id)->latest('paid_at')->limit(50)->get()
                ->each(fn (SalesOrderPayment $payment) => $add($payment, 'sales_order_payment', 'payment'));
            InventoryMovement::query()->where('user_id', $model->id)->latest()->limit(50)->get()
                ->each(fn (InventoryMovement $movement) => $add($movement, 'inventory_movement', 'inventory_movement'));
            OperationalExpense::query()->where('created_by', $model->id)->latest()->limit(50)->get()
                ->each(fn (OperationalExpense $expense) => $add($expense, 'operational_expense', 'expense'));
        }

        return $nodes->unique(fn (array $node) => $node['type'].'-'.$node['id'].'-'.$node['relation']);
    }

    /** @param Collection<int, array<string, mixed>> $relationships
     * @return Collection<int, array<string, mixed>>
     */
    private function timeline(string $type, Model $model, Collection $relationships, User $user): Collection
    {
        $events = collect([$this->event('business', 'created', 'Registro creado', $model->created_at, $this->node($type, $model, $user, true), $model)]);

        foreach ($relationships as $node) {
            if ($node['restricted']) {
                continue;
            }
            $events->push($this->event(
                $this->eventCategory($node['type'], $node['relation']),
                $node['relation'],
                $node['label'],
                $node['occurred_at'],
                $node,
                null,
            ));
        }

        if ($type === 'sales_order' && $model->cancelled_at) {
            $events->push($this->event('cancellation', 'cancelled', 'Venta anulada', $model->cancelled_at, $this->node($type, $model, $user, true), $model, $model->canceller?->name, $model->cancellation_reason));
        }
        if ($type === 'sales_return') {
            if ($model->approved_at) {
                $events->push($this->event('status', 'approved', 'Devolucion aprobada', $model->approved_at, $this->node($type, $model, $user, true), $model, $model->approver?->name));
            }
            if ($model->settled_at) {
                $events->push($this->event('financial', 'settled', 'Devolucion liquidada', $model->settled_at, $this->node($type, $model, $user, true), $model, $model->settler?->name));
            }
        }
        if ($type === 'cashier_queue_entry') {
            if ($model->opened_at) {
                $events->push($this->event('status', 'opened', 'Abierto en Caja', $model->opened_at, $this->node($type, $model, $user, true), $model, $model->opener?->name));
            }
            if ($model->completed_at) {
                $events->push($this->event('financial', 'completed', 'Completado en Caja', $model->completed_at, $this->node($type, $model, $user, true), $model, $model->completer?->name));
            }
        }
        if ($type === 'operational_expense') {
            $model->events()->with('user:id,name')->get()->each(function ($expenseEvent) use ($events, $type, $model, $user): void {
                $events->push([
                    'id' => 'expense-event-'.$expenseEvent->id,
                    'category' => in_array($expenseEvent->event_type, ['reversed', 'cancelled'], true) ? 'reversal' : 'status',
                    'event_type' => $expenseEvent->event_type,
                    'label' => Str::headline($expenseEvent->event_type),
                    'occurred_at' => $expenseEvent->created_at?->toISOString(),
                    'user' => $expenseEvent->user ? ['id' => $expenseEvent->user->id, 'name' => $expenseEvent->user->name] : null,
                    'status' => $expenseEvent->to_status,
                    'amount' => $model->amount !== null ? (float) $model->amount : null,
                    'quantity' => null,
                    'before' => $expenseEvent->before,
                    'after' => $expenseEvent->after,
                    'reason' => $expenseEvent->reason,
                    'document' => $this->node($type, $model, $user, true),
                ]);
            });
        }

        return $events->filter(fn (array $event) => ! empty($event['occurred_at']));
    }

    /** @return array<string, mixed> */
    private function node(string $type, Model $model, User $user, bool $root, ?string $relation = null): array
    {
        $canView = $this->canView($user, $type, $model);
        $restricted = ! $canView;

        return [
            'type' => $type,
            'id' => (int) $model->getKey(),
            'relation' => $relation,
            'label' => $restricted ? $this->typeLabel($type) : $this->label($type, $model),
            'number' => $restricted ? null : $this->number($type, $model),
            'status' => $restricted ? null : $model->getAttribute('status'),
            'occurred_at' => $restricted ? null : $this->occurredAt($type, $model),
            'amount' => $restricted ? null : $this->amount($type, $model),
            'quantity' => $restricted ? null : $this->quantity($type, $model),
            'href' => $canView ? $this->href($type, $model) : null,
            'can_view' => $canView,
            'restricted' => $restricted,
            'traceable' => $this->registry->modelClass($type) !== null && ($root || $canView),
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $category, string $eventType, string $label, mixed $occurredAt, array $document, ?Model $model = null, ?string $actorName = null, ?string $reason = null): array
    {
        return [
            'id' => $eventType.'-'.$document['type'].'-'.$document['id'].'-'.md5((string) $occurredAt),
            'category' => $category,
            'event_type' => $eventType,
            'label' => $label,
            'occurred_at' => method_exists($occurredAt, 'toISOString') ? $occurredAt->toISOString() : $occurredAt,
            'user' => $actorName ? ['id' => null, 'name' => $actorName] : $this->actor($model),
            'status' => $document['status'],
            'amount' => $document['amount'],
            'quantity' => $document['quantity'],
            'before' => null,
            'after' => null,
            'reason' => $reason,
            'document' => $document,
        ];
    }

    private function actor(?Model $model): ?array
    {
        if (! $model) {
            return null;
        }
        foreach (['user', 'creator', 'opener', 'completer'] as $relation) {
            if (method_exists($model, $relation) && ($actor = $model->{$relation})) {
                return ['id' => (int) $actor->id, 'name' => (string) $actor->name];
            }
        }

        return null;
    }

    private function eventCategory(string $type, ?string $relation): string
    {
        return match (true) {
            in_array($type, ['inventory_movement'], true) => 'inventory',
            in_array($type, ['sales_order_payment', 'purchase_order_payment', 'account_payment_batch', 'cash_movement', 'customer_transaction', 'account_ledger_entry'], true) => 'financial',
            in_array($relation, ['return', 'credit_note'], true) => 'reversal',
            in_array($type, ['cashier_queue_entry'], true) => 'status',
            default => 'business',
        };
    }

    private function typeForModel(?Model $model): ?string
    {
        if (! $model) {
            return null;
        }

        return $this->registry->typeFor($model);
    }

    /** @return array{type: string, model: Model}|null */
    private function resolveReference(?string $referenceType, mixed $referenceId): ?array
    {
        if (! $referenceType || ! $referenceId) {
            return null;
        }
        $type = $this->registry->normalize($referenceType);
        if (! $type) {
            return null;
        }
        $modelClass = $this->registry->modelClass($type);
        if (! $modelClass) {
            return null;
        }
        $model = $modelClass::query()->find($referenceId);

        return $model ? ['type' => $type, 'model' => $model] : null;
    }

    private function queueFor(Model $model): Collection
    {
        return CashierQueueEntry::query()
            ->where('source_type', $model::class)
            ->where('source_id', $model->getKey())
            ->orderBy('created_at')
            ->get();
    }

    private function inventoryFor(string $referenceType, int $referenceId): Collection
    {
        $aliases = match ($referenceType) {
            'sales_order' => ['sales_order', 'sale'],
            'purchase_order' => ['purchase_order', 'purchase'],
            default => [$referenceType],
        };

        return InventoryMovement::query()
            ->whereIn('reference_type', $aliases)
            ->where('reference_id', $referenceId)
            ->orderBy('created_at')
            ->get();
    }

    private function relatedInventory(InventoryMovement $movement): Collection
    {
        $query = InventoryMovement::query()->whereKeyNot($movement->id);
        if ($movement->movement_group_uuid) {
            $grouped = (clone $query)->where('movement_group_uuid', $movement->movement_group_uuid)->orderBy('created_at')->get();
            if ($grouped->isNotEmpty()) {
                return $grouped;
            }
        }
        if ($movement->reference_type && $movement->reference_id) {
            return $query->where('reference_type', $movement->reference_type)
                ->where('reference_id', $movement->reference_id)
                ->orderBy('created_at')
                ->get();
        }

        return collect();
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'sales_order' => 'Venta / factura',
            'sales_quote' => 'Cotizacion',
            'sales_return' => 'Devolucion',
            'sales_credit_note' => 'Nota de credito',
            'purchase_order' => 'Orden de compra',
            'operational_expense' => 'Gasto',
            'operational_expense_beneficiary' => 'Beneficiario',
            'operational_expense_category' => 'Categoria de gasto',
            'product' => 'Producto',
            'product_variant' => 'Variante',
            'inventory_lot' => 'Lote FIFO',
            'warehouse' => 'Almacen',
            'user' => 'Usuario',
            'cashier_queue_entry' => 'Entrada de Caja',
            'pos_session' => 'Sesion de Caja',
            'sales_order_payment', 'purchase_order_payment' => 'Pago',
            'inventory_movement' => 'Movimiento de inventario',
            'inventory_transfer' => 'Transferencia de inventario',
            'cash_movement' => 'Movimiento de caja',
            'customer_transaction' => 'Movimiento CxC',
            'account_ledger_entry' => 'Movimiento de cuenta',
            'account_payment_batch' => 'Pago de cuenta',
            'customer' => 'Cliente',
            'supplier' => 'Proveedor',
            default => Str::headline($type),
        };
    }

    private function label(string $type, Model $model): string
    {
        return match ($type) {
            'customer', 'supplier', 'operational_expense_beneficiary', 'operational_expense_category', 'product', 'warehouse', 'user' => (string) $model->name,
            'product_variant' => (string) $model->display_name,
            'inventory_lot' => 'Lote '.($model->batch_number ?: '#'.$model->id),
            'operational_expense' => (string) ($model->description ?: $model->category_label),
            'inventory_movement' => $this->typeLabel($type).' #'.$model->id,
            'account_payment_batch' => $model->account_type === 'supplier'
                ? 'Pago CxP '.($model->batch_number ?: '#'.$model->id)
                : 'Cobro CxC '.($model->batch_number ?: '#'.$model->id),
            default => $this->typeLabel($type).' '.($this->number($type, $model) ?? '#'.$model->id),
        };
    }

    private function number(string $type, Model $model): ?string
    {
        $attribute = match ($type) {
            'sales_order' => 'order_number',
            'sales_quote' => 'quote_number',
            'sales_return' => 'return_number',
            'sales_credit_note' => 'credit_note_number',
            'purchase_order' => 'po_number',
            'operational_expense' => 'reference_number',
            'cash_movement' => 'reference_number',
            'account_ledger_entry' => 'document_number',
            'account_payment_batch' => 'batch_number',
            'inventory_transfer' => 'reference',
            'product' => 'system_id',
            'product_variant' => 'system_id',
            'inventory_lot' => 'batch_number',
            'warehouse' => 'code',
            'user' => 'username',
            default => null,
        };

        return $attribute ? ($model->getAttribute($attribute) ?: '#'.$model->id) : '#'.$model->id;
    }

    private function href(string $type, Model $model): ?string
    {
        return match ($type) {
            'sales_order' => '/sales-orders/'.$model->id,
            'sales_quote' => '/sales-quotes/'.$model->id,
            'sales_return' => '/returns/'.$model->id,
            'sales_credit_note' => null,
            'purchase_order' => '/purchase-orders/'.$model->id,
            'operational_expense' => '/operational-expenses?expense_id='.$model->id,
            'operational_expense_beneficiary' => '/operational-expense-beneficiaries/'.$model->id,
            'operational_expense_category' => '/operational-expenses?category='.$model->slug,
            'cashier_queue_entry' => '/pos/cashier?queue_entry_id='.$model->id,
            'pos_session' => '/pos-sessions?session_id='.$model->id,
            'sales_order_payment' => $model->sales_order_id ? '/sales-orders/'.$model->sales_order_id.'?tab=payments' : null,
            'purchase_order_payment' => $model->purchase_order_id ? '/purchase-orders/'.$model->purchase_order_id.'?tab=payments' : null,
            'inventory_movement' => '/inventory-movements/'.$model->id,
            'inventory_transfer' => '/inventory-transfers/'.$model->id,
            'product' => '/products/'.$model->id,
            'product_variant' => $model->product_id ? '/products/'.$model->product_id.'?variant_id='.$model->id : null,
            'inventory_lot' => null,
            'warehouse' => '/warehouses/'.$model->id,
            'user' => '/users/'.$model->id.'/edit',
            'cash_movement' => $model->pos_session_id ? '/pos-sessions?session_id='.$model->pos_session_id : null,
            'customer_transaction' => $model->customer_id ? '/ar?customer_id='.$model->customer_id : null,
            'account_ledger_entry' => $model->account_type === 'customer' ? '/ar?customer_id='.$model->account_id : '/accounts-payable?supplier_id='.$model->account_id,
            'account_payment_batch' => $model->account_type === 'customer' ? '/ar?customer_id='.$model->account_id : '/accounts-payable?supplier_id='.$model->account_id,
            'customer' => '/customers/'.$model->id,
            'supplier' => '/suppliers/'.$model->id,
            default => null,
        };
    }

    private function occurredAt(string $type, Model $model): ?string
    {
        $value = match ($type) {
            'sales_order_payment', 'purchase_order_payment', 'account_payment_batch' => $model->paid_at,
            'sales_credit_note' => $model->issued_at,
            'customer_transaction' => $model->transaction_date,
            'account_ledger_entry' => $model->occurred_at,
            'cashier_queue_entry' => $model->completed_at ?? $model->opened_at ?? $model->created_at,
            'inventory_lot' => $model->received_at,
            default => $model->business_datetime ?? $model->created_at,
        };

        return $value?->toISOString();
    }

    private function amount(string $type, Model $model): ?float
    {
        $value = match ($type) {
            'sales_order', 'purchase_order' => $model->total,
            'sales_quote' => $model->total,
            'sales_return', 'sales_credit_note' => $model->total_amount,
            'operational_expense', 'sales_order_payment', 'purchase_order_payment', 'account_payment_batch', 'cash_movement', 'customer_transaction' => $model->amount,
            'cashier_queue_entry' => $model->final_amount ?? $model->estimated_amount,
            'account_ledger_entry' => max((float) $model->debit, (float) $model->credit),
            'inventory_lot' => (float) $model->landed_unit_cost * (int) $model->quantity_available,
            default => null,
        };

        return $value === null ? null : (float) $value;
    }

    private function quantity(string $type, Model $model): ?float
    {
        return match ($type) {
            'inventory_movement' => (float) $model->quantity,
            'inventory_lot' => (float) $model->quantity_available,
            default => null,
        };
    }
}
