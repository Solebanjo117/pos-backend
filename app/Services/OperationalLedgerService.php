<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationalLedgerService
{
    /** @var array<string, array{code: string, name: string, type: string, report_group: string, normal_balance: string}> */
    private const ACCOUNTS = [
        'cash' => ['code' => '1100', 'name' => 'Efectivo', 'type' => 'asset', 'report_group' => 'cash', 'normal_balance' => 'debit'],
        'bank' => ['code' => '1110', 'name' => 'Banco', 'type' => 'asset', 'report_group' => 'bank', 'normal_balance' => 'debit'],
        'accounts_receivable' => ['code' => '1200', 'name' => 'Cuentas por cobrar', 'type' => 'asset', 'report_group' => 'accounts_receivable', 'normal_balance' => 'debit'],
        'inventory' => ['code' => '1300', 'name' => 'Inventario', 'type' => 'asset', 'report_group' => 'inventory', 'normal_balance' => 'debit'],
        'accounts_payable' => ['code' => '2100', 'name' => 'Cuentas por pagar', 'type' => 'liability', 'report_group' => 'accounts_payable', 'normal_balance' => 'credit'],
        'customer_credits' => ['code' => '2110', 'name' => 'Saldos a favor de clientes', 'type' => 'liability', 'report_group' => 'customer_credits', 'normal_balance' => 'credit'],
        'pending_refunds' => ['code' => '2120', 'name' => 'Reembolsos pendientes', 'type' => 'liability', 'report_group' => 'pending_refunds', 'normal_balance' => 'credit'],
        'sales_tax_payable' => ['code' => '2200', 'name' => 'ITBMS por pagar', 'type' => 'liability', 'report_group' => 'other_liabilities', 'normal_balance' => 'credit'],
        'contributed_capital' => ['code' => '3000', 'name' => 'Capital aportado', 'type' => 'equity', 'report_group' => 'contributed_capital', 'normal_balance' => 'credit'],
        'retained_earnings' => ['code' => '3100', 'name' => 'Resultados acumulados', 'type' => 'equity', 'report_group' => 'retained_earnings', 'normal_balance' => 'credit'],
        'sales_revenue' => ['code' => '4000', 'name' => 'Ventas', 'type' => 'income', 'report_group' => 'income', 'normal_balance' => 'credit'],
        'sales_returns' => ['code' => '4010', 'name' => 'Descuentos y devoluciones sobre ventas', 'type' => 'income', 'report_group' => 'contra_income', 'normal_balance' => 'debit'],
        'operational_expenses' => ['code' => '5000', 'name' => 'Gastos operativos', 'type' => 'expense', 'report_group' => 'expense', 'normal_balance' => 'debit'],
        'cost_of_goods_sold' => ['code' => '5100', 'name' => 'Costo de ventas', 'type' => 'expense', 'report_group' => 'cost_of_goods_sold', 'normal_balance' => 'debit'],
    ];

    public function __construct(private readonly LedgerPostingService $postingService) {}

    public function syncSalesOrder(SalesOrder $order): ?JournalEntry
    {
        $order->refresh();
        $order->load(['items', 'customer']);
        $recognized = in_array($order->status, ['confirmed', 'processing', 'completed'], true)
            || ($order->status === 'cancelled' && $order->cancellation_return_id !== null);
        $subtotal = $recognized ? $this->money((float) $order->total - (float) $order->tax_amount) : 0.0;
        $tax = $recognized ? $this->money($order->tax_amount) : 0.0;
        $cogs = $recognized ? $this->money($order->items->sum(fn ($item): float => (float) $item->unit_cost * (int) $item->quantity)) : 0.0;

        return $this->syncDesired(
            'sales_order_recognition',
            (int) $order->id,
            [
                'accounts_receivable' => $this->money($subtotal + $tax),
                'sales_revenue' => -$subtotal,
                'sales_tax_payable' => -$tax,
                'cost_of_goods_sold' => $cogs,
                'inventory' => -$cogs,
            ],
            $order->business_datetime ?? $order->date ?? $order->created_at,
            $order->items->first()?->warehouse_id,
            $order->user_id,
            'Venta '.$order->order_number,
            ['document' => $order->order_number, 'status' => $order->status],
        );
    }

    public function syncSalesPayment(SalesOrderPayment $payment): ?JournalEntry
    {
        $payment->refresh();
        $payment->loadMissing('salesOrder');
        $amount = $this->money(abs((float) $payment->amount));
        if ($amount <= 0 || ! $payment->salesOrder) {
            return null;
        }

        $bucket = ($payment->liquidity_bucket ?? PaymentMethod::liquidityBucket($payment->method));
        $liquidity = match ($bucket) {
            PaymentMethod::LIQUIDITY_CASH => 'cash',
            PaymentMethod::LIQUIDITY_BANK => 'bank',
            default => $payment->method === 'store_credit' ? 'customer_credits' : null,
        };

        if ($liquidity === null) {
            return null;
        }

        if ($payment->account_payment_batch_id && \App\Models\AccountPaymentBatch::query()->whereKey($payment->account_payment_batch_id)->where('receipt_accounting_version', 1)->exists()) {
            $liquidity = 'customer_credits';
        }
        $isRefund = $payment->sales_return_id !== null || (float) $payment->amount < 0;
        $desired = $isRefund
            ? ['pending_refunds' => $amount, $liquidity => -$amount]
            : [$liquidity => $amount, 'accounts_receivable' => -$amount];

        return $this->syncDesired(
            'sales_order_payment',
            (int) $payment->id,
            $desired,
            $payment->business_datetime ?? $payment->paid_at ?? $payment->created_at,
            $payment->salesOrder->items()->value('warehouse_id'),
            $payment->created_by,
            $isRefund ? 'Reembolso de venta' : 'Cobro de venta '.$payment->salesOrder->order_number,
            ['method' => $payment->method, 'reference' => $payment->reference_number],
        );
    }

    public function syncCustomerReceipt(\App\Models\AccountPaymentBatch $receipt, bool $reverse = false): ?JournalEntry
    {
        if ($receipt->receipt_accounting_version !== 1 || $receipt->account_type !== 'customer' || $receipt->direction !== 'incoming') {
            return null;
        }
        $bucket = $receipt->liquidity_bucket;
        if (! in_array($bucket, ['cash', 'bank'], true)) {
            return null;
        }

        return $this->syncDesired('customer_receipt', (int) $receipt->id,
            $reverse ? [] : [$bucket => (float) $receipt->amount, 'customer_credits' => -(float) $receipt->amount],
            $reverse ? now() : ($receipt->business_datetime ?? $receipt->paid_at), $receipt->warehouse_id, $receipt->created_by,
            $reverse ? 'Reversa recibo '.$receipt->batch_number : 'Recibo '.$receipt->batch_number);
    }

    public function reverseReceiptApplication(SalesOrderPayment $payment): ?JournalEntry
    {
        if (! $payment->account_payment_batch_id || ! \App\Models\AccountPaymentBatch::query()->whereKey($payment->account_payment_batch_id)->where('receipt_accounting_version', 1)->exists()) {
            return null;
        }

        return $this->syncDesired('sales_order_payment', (int) $payment->id, [], now(), $payment->warehouse_id, auth()->id(), 'Reversa aplicación recibo');
    }

    public function syncPurchaseOrder(PurchaseOrder $order): ?JournalEntry
    {
        $order->refresh();
        $order->load(['items', 'supplier']);
        $recognized = in_array($order->status, ['partial', 'received'], true);
        $total = 0.0;

        if ($recognized) {
            foreach ($order->items as $item) {
                $ordered = max(1, (int) $item->quantity_ordered);
                $received = max(0, (int) $item->quantity_received);
                $ratio = min(1, $received / $ordered);
                $total += ((float) $item->subtotal + (float) $item->tax_amount + (float) ($item->misc_cost ?? 0)) * $ratio;
            }

            if ($order->status === 'received') {
                $total += (float) ($order->shipping_cost ?? 0);
            }
        }

        $total = $this->money($total);

        return $this->syncDesired(
            'purchase_order_recognition',
            (int) $order->id,
            ['inventory' => $total, 'accounts_payable' => -$total],
            $order->business_datetime ?? $order->received_date ?? $order->order_date ?? $order->created_at,
            $order->warehouse_id,
            $order->user_id,
            'Recepción de compra '.$order->po_number,
            ['document' => $order->po_number, 'status' => $order->status],
        );
    }

    public function syncPurchasePayment(PurchaseOrderPayment $payment): ?JournalEntry
    {
        $payment->refresh();
        $payment->loadMissing('purchaseOrder');
        $amount = $this->money(abs((float) $payment->amount));
        if ($amount <= 0 || ! $payment->purchaseOrder) {
            return null;
        }

        $bucket = ($payment->liquidity_bucket ?? PaymentMethod::liquidityBucket($payment->method));
        $liquidity = $bucket === PaymentMethod::LIQUIDITY_CASH ? 'cash' : ($bucket === PaymentMethod::LIQUIDITY_BANK ? 'bank' : null);
        if ($liquidity === null) {
            return null;
        }

        return $this->syncDesired(
            'purchase_order_payment',
            (int) $payment->id,
            ['accounts_payable' => $amount, $liquidity => -$amount],
            $payment->business_datetime ?? $payment->paid_at ?? $payment->created_at,
            $payment->purchaseOrder->warehouse_id,
            $payment->user_id,
            'Pago de compra '.$payment->purchaseOrder->po_number,
            ['method' => $payment->method, 'reference' => $payment->reference_number],
        );
    }

    public function syncSalesReturn(SalesReturn $return): ?JournalEntry
    {
        $return->refresh();
        $return->load(['items.salesOrderItem', 'salesOrder']);
        $approved = $return->status === 'approved';
        $subtotal = $approved ? $this->money($return->items->sum('subtotal')) : 0.0;
        $total = $approved ? $this->money($return->total_amount) : 0.0;
        $tax = $this->money(max(0, $total - $subtotal));
        $cogs = $approved ? $this->money($return->items->sum(fn ($item): float => (float) ($item->salesOrderItem?->unit_cost ?? 0) * (int) $item->quantity)) : 0.0;
        $receivableApplied = $this->money($approved ? $return->receivable_applied_amount : 0);
        $moneyRefund = $this->money($approved ? $return->money_refund_amount : 0);
        $storeCredit = $this->money($approved ? $return->store_credit_amount : 0);

        // Legacy returns did not persist the settlement split. Their historical
        // effect was a reduction of the sale balance, so keep that behavior
        // explicit instead of leaving an unbalanced entry.
        $unallocated = $this->money($total - $receivableApplied - $moneyRefund - $storeCredit);
        if ($approved && $unallocated > 0) {
            $receivableApplied = $this->money($receivableApplied + $unallocated);
        }

        return $this->syncDesired(
            'sales_return_recognition',
            (int) $return->id,
            [
                'sales_returns' => $subtotal,
                'sales_tax_payable' => $tax,
                'accounts_receivable' => -$receivableApplied,
                'pending_refunds' => -$moneyRefund,
                'customer_credits' => -$storeCredit,
                'inventory' => $cogs,
                'cost_of_goods_sold' => -$cogs,
            ],
            $return->approved_at ?? $return->created_at,
            $return->warehouse_id,
            $return->approved_by ?? $return->user_id,
            'Devolución '.$return->return_number,
            ['document' => $return->return_number, 'status' => $return->status],
        );
    }

    public function syncCapitalMovement(CapitalMovement $movement): ?JournalEntry
    {
        $amount = $this->money($movement->amount);
        if ($amount <= 0) {
            return null;
        }

        $liquidity = $movement->financial_account_id ? 'bank' : 'cash';
        $isInbound = $movement->movement_type === CapitalMovement::TYPE_IN;
        $desired = $isInbound ? [$liquidity => $amount, 'contributed_capital' => -$amount] : [$liquidity => -$amount, 'contributed_capital' => $amount];
        if ($movement->source_type === 'allocated_cash_operation') {
            $desired = ['contributed_capital' => $isInbound ? -$amount : $amount];
            foreach (\App\Models\OperationalPayment::query()->where('source_type', \App\Models\CashOperationRequest::class)->where('source_id', $movement->source_id)->get() as $payment) {
                $desired[$payment->liquidity_bucket] = ($desired[$payment->liquidity_bucket] ?? 0) + ($isInbound ? 1 : -1) * (float) $payment->amount;
            }
        }

        return $this->syncDesired(
            'capital_movement',
            (int) $movement->id,
            $desired,
            $movement->business_datetime ?? $movement->movement_date ?? $movement->created_at,
            $movement->warehouse_id,
            $movement->user_id,
            $isInbound ? 'Aporte de capital' : 'Retiro de capital',
            ['reason' => $movement->reason],
        );
    }

    /** @return array<string, int> */
    public function backfill(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $counts = ['sales' => 0, 'sales_payments' => 0, 'returns' => 0, 'purchases' => 0, 'purchase_payments' => 0, 'capital' => 0];

        SalesOrder::query()->withTrashed()->whereDate('date', '<=', $asOf)->orderBy('date')->orderBy('id')->each(function (SalesOrder $order) use (&$counts): void {
            $counts['sales'] += $this->syncSalesOrder($order) ? 1 : 0;
        });
        SalesOrderPayment::query()->where('paid_at', '<=', $asOf)->orderBy('paid_at')->orderBy('id')->each(function (SalesOrderPayment $payment) use (&$counts): void {
            $counts['sales_payments'] += $this->syncSalesPayment($payment) ? 1 : 0;
        });
        SalesReturn::query()->withTrashed()->where('created_at', '<=', $asOf)->orderBy('created_at')->orderBy('id')->each(function (SalesReturn $return) use (&$counts): void {
            $counts['returns'] += $this->syncSalesReturn($return) ? 1 : 0;
        });
        PurchaseOrder::query()->withTrashed()->whereDate('order_date', '<=', $asOf)->orderBy('order_date')->orderBy('id')->each(function (PurchaseOrder $order) use (&$counts): void {
            $counts['purchases'] += $this->syncPurchaseOrder($order) ? 1 : 0;
        });
        PurchaseOrderPayment::query()->where('paid_at', '<=', $asOf)->orderBy('paid_at')->orderBy('id')->each(function (PurchaseOrderPayment $payment) use (&$counts): void {
            $counts['purchase_payments'] += $this->syncPurchasePayment($payment) ? 1 : 0;
        });
        CapitalMovement::query()->whereDate('movement_date', '<=', $asOf)->orderBy('movement_date')->orderBy('id')->each(function (CapitalMovement $movement) use (&$counts): void {
            $counts['capital'] += $this->syncCapitalMovement($movement) ? 1 : 0;
        });

        return $counts;
    }

    public function closeYear(int $year, ?int $userId = null): ?JournalEntry
    {
        $postingKey = 'accounting_year_close:'.$year;
        $existing = JournalEntry::query()->where('posting_key', $postingKey)->first();
        if ($existing) {
            return $existing;
        }

        $from = Carbon::create($year, 1, 1, 0, 0, 0, 'America/Panama')->utc();
        $to = Carbon::create($year, 12, 31, 23, 59, 59, 'America/Panama')->utc();
        $accounts = LedgerAccount::query()->whereIn('type', ['income', 'expense'])->get();
        $lines = [];
        $retainedDelta = 0.0;

        foreach ($accounts as $account) {
            $debit = (float) JournalLine::query()->where('ledger_account_id', $account->id)
                ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', ['posted', 'reversed'])->whereBetween('occurred_at', [$from, $to]))
                ->sum('debit');
            $credit = (float) JournalLine::query()->where('ledger_account_id', $account->id)
                ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', ['posted', 'reversed'])->whereBetween('occurred_at', [$from, $to]))
                ->sum('credit');
            $raw = $this->money($debit - $credit);
            if (abs($raw) < 0.005) {
                continue;
            }

            $lines[] = $this->lineForAccount($account, -$raw);
            $retainedDelta += $raw;
        }

        if ($lines === []) {
            return null;
        }

        $lines[] = $this->lineForAccount($this->account('retained_earnings'), $this->money($retainedDelta));

        return $this->postingService->postManualEntry([
            'posting_key' => $postingKey,
            'source_type' => 'accounting_year_close',
            'source_id' => $year,
            'occurred_at' => $to,
            'memo' => 'Cierre contable del ejercicio '.$year,
            'created_by' => $userId,
            'metadata' => ['year' => $year],
            'lines' => $lines,
        ]);
    }

    /**
     * Signed amounts use debit as positive and credit as negative.
     *
     * @param  array<string, float|int>  $desired
     * @param  array<string, mixed>  $metadata
     */
    private function syncDesired(
        string $sourceType,
        int $sourceId,
        array $desired,
        mixed $occurredAt,
        ?int $warehouseId,
        ?int $userId,
        string $memo,
        array $metadata = [],
    ): ?JournalEntry {
        return DB::transaction(function () use ($sourceType, $sourceId, $desired, $occurredAt, $warehouseId, $userId, $memo, $metadata): ?JournalEntry {
            $desiredByAccount = collect($desired)
                ->mapWithKeys(fn (float|int $amount, string $key): array => [$this->account($key)->id => $this->money($amount)])
                ->filter(fn (float $amount): bool => abs($amount) >= 0.005);
            $currentByAccount = JournalLine::query()
                ->selectRaw('ledger_account_id, SUM(debit - credit) AS raw_balance')
                ->whereHas('journalEntry', fn ($query) => $query
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->whereIn('status', ['posted', 'reversed']))
                ->groupBy('ledger_account_id')
                ->pluck('raw_balance', 'ledger_account_id');

            $accountIds = $desiredByAccount->keys()->merge($currentByAccount->keys())->unique();
            $delta = $accountIds->mapWithKeys(function (int|string $accountId) use ($desiredByAccount, $currentByAccount): array {
                $value = $this->money((float) $desiredByAccount->get($accountId, 0) - (float) $currentByAccount->get($accountId, 0));

                return [(int) $accountId => $value];
            })->filter(fn (float $amount): bool => abs($amount) >= 0.005);

            if ($delta->isEmpty()) {
                return null;
            }

            if (abs((float) $delta->sum()) > 0.005) {
                throw new RuntimeException('El asiento operativo calculado no está balanceado.');
            }

            $stateHash = hash('sha256', json_encode($desiredByAccount->sortKeys()->all(), JSON_THROW_ON_ERROR));
            $accounts = LedgerAccount::query()->whereKey($delta->keys())->get()->keyBy('id');
            $lines = $delta->map(fn (float $amount, int $accountId): array => $this->lineForAccount($accounts->get($accountId), $amount))->values()->all();

            return $this->postingService->postManualEntry([
                'posting_key' => $sourceType.':'.$sourceId.':'.$stateHash,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'warehouse_id' => $warehouseId,
                'occurred_at' => $occurredAt ?? now(),
                'memo' => $memo,
                'created_by' => $userId,
                'metadata' => [...$metadata, 'state_hash' => $stateHash, 'consolidated_balance' => true],
                'lines' => $lines,
            ]);
        });
    }

    private function account(string $systemKey): LedgerAccount
    {
        $definition = self::ACCOUNTS[$systemKey] ?? throw new RuntimeException('Cuenta contable no configurada: '.$systemKey);

        return LedgerAccount::query()->updateOrCreate(
            ['code' => $definition['code']],
            [
                'system_key' => $systemKey,
                'name' => $definition['name'],
                'type' => $definition['type'],
                'report_group' => $definition['report_group'],
                'normal_balance' => $definition['normal_balance'],
                'is_active' => true,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function lineForAccount(LedgerAccount $account, float $rawAmount): array
    {
        return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'debit' => $rawAmount > 0 ? $this->money($rawAmount) : 0,
            'credit' => $rawAmount < 0 ? $this->money(abs($rawAmount)) : 0,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
