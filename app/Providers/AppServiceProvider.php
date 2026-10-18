<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Models\CapitalMovement;
use App\Models\FinancialAccount;
use App\Models\OperationalExpense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Services\FinancialLiquiditySyncService;
use App\Services\OperationalLedgerService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\CashMovement::class, SalesOrderPayment::class, \App\Models\AccountPaymentBatch::class] as $cashSource) {
            $cashSource::created(fn ($source) => app(\App\Services\WarehouseCashService::class)->capture($source));
            $cashSource::deleting(function ($source): void {
                if ($source instanceof SalesOrderPayment || $source instanceof \App\Models\AccountPaymentBatch) {
                    $scope = $source instanceof SalesOrderPayment && ! $source->account_payment_batch_id ? 'sales' : 'receipts';
                    $sourceId = $source instanceof SalesOrderPayment ? ($source->account_payment_batch_id ?? $source->id) : $source->id;
                    $warehouseId = $source->warehouse_id ?? $source->posSession?->warehouse_id;
                    if ($warehouseId) {
                        \App\Models\Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
                    }
                    if (\App\Models\CashDepositAllocation::query()->where('source_scope', $scope)->where('source_id', $sourceId)->whereNull('released_at')->exists()) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'Reverse primero los depósitos asignados a este cobro.']);
                    }
                }
                app(\App\Services\WarehouseCashService::class)->capture($source, true);
            });
        }
        foreach ([SalesOrderPayment::class, PurchaseOrderPayment::class, \App\Models\AccountPaymentBatch::class] as $paymentClass) {
            $paymentClass::creating(function ($payment): void {
                $payment->liquidity_bucket = \App\Models\PaymentMethod::liquidityBucket($payment->method);
                if ($payment instanceof SalesOrderPayment) {
                    $payment->warehouse_id = app(\App\Services\WarehouseCashService::class)->salesPaymentWarehouse($payment);
                }
            });
        }
        foreach ([
            SalesOrderPayment::class,
            PurchaseOrderPayment::class,
            OperationalExpense::class,
            CapitalMovement::class,
            \App\Models\AccountPaymentBatch::class,
        ] as $modelClass) {
            $modelClass::created(fn ($model) => app(FinancialLiquiditySyncService::class)->sync($model));
            $modelClass::deleting(fn ($model) => app(FinancialLiquiditySyncService::class)->reverse($model));
        }
        OperationalExpense::updated(function (OperationalExpense $expense): void {
            $syncService = app(FinancialLiquiditySyncService::class);
            if (in_array($expense->status, ['cancelled', 'reversed'], true)) {
                $syncService->reverse($expense);

                return;
            }

            $syncService->sync($expense);
        });

        SalesOrder::saved(fn (SalesOrder $order) => app(OperationalLedgerService::class)->syncSalesOrder($order));
        SalesOrderItem::saved(fn (SalesOrderItem $item) => app(OperationalLedgerService::class)->syncSalesOrder($item->salesOrder));
        SalesOrderItem::deleted(fn (SalesOrderItem $item) => app(OperationalLedgerService::class)->syncSalesOrder($item->salesOrder));
        SalesOrderPayment::created(fn (SalesOrderPayment $payment) => app(OperationalLedgerService::class)->syncSalesPayment($payment));
        SalesOrderPayment::deleting(fn (SalesOrderPayment $payment) => app(OperationalLedgerService::class)->reverseReceiptApplication($payment));
        \App\Models\AccountPaymentBatch::created(fn ($receipt) => app(OperationalLedgerService::class)->syncCustomerReceipt($receipt));
        \App\Models\AccountPaymentBatch::deleting(fn ($receipt) => app(OperationalLedgerService::class)->syncCustomerReceipt($receipt, true));
        PurchaseOrder::saved(fn (PurchaseOrder $order) => app(OperationalLedgerService::class)->syncPurchaseOrder($order));
        PurchaseOrderItem::saved(fn (PurchaseOrderItem $item) => app(OperationalLedgerService::class)->syncPurchaseOrder($item->purchaseOrder));
        PurchaseOrderItem::deleted(fn (PurchaseOrderItem $item) => app(OperationalLedgerService::class)->syncPurchaseOrder($item->purchaseOrder));
        PurchaseOrderPayment::created(fn (PurchaseOrderPayment $payment) => app(OperationalLedgerService::class)->syncPurchasePayment($payment));
        SalesReturn::saved(fn (SalesReturn $return) => app(OperationalLedgerService::class)->syncSalesReturn($return));
        SalesReturnItem::saved(fn (SalesReturnItem $item) => app(OperationalLedgerService::class)->syncSalesReturn($item->salesReturn));
        SalesReturnItem::deleted(fn (SalesReturnItem $item) => app(OperationalLedgerService::class)->syncSalesReturn($item->salesReturn));
        CapitalMovement::created(fn (CapitalMovement $movement) => app(OperationalLedgerService::class)->syncCapitalMovement($movement));
        FinancialAccount::saved(function (FinancialAccount $account): void {
            $account->ledgerAccount()->update([
                'report_group' => $account->type === 'bank' ? 'bank' : 'other_assets',
                'normal_balance' => 'debit',
            ]);
        });

        $listener = new LogAuthenticationEvents;
        Event::listen(Login::class, [$listener, 'handleLogin']);
        Event::listen(Logout::class, [$listener, 'handleLogout']);

        RateLimiter::for('product-import-heavy', fn (Request $request): Limit => Limit::perMinute(6)->by($this->rateLimitKey($request, 'product-import-heavy')));
        RateLimiter::for('product-import-chunk', fn (Request $request): Limit => Limit::perMinute(120)->by($this->chunkRateLimitKey($request, 'product-import-chunk')));
        RateLimiter::for('product-import-export', fn (Request $request): Limit => Limit::perMinute(10)->by($this->rateLimitKey($request, 'product-import-export')));
        RateLimiter::for('product-import-mutation', fn (Request $request): Limit => Limit::perMinute(30)->by($this->rateLimitKey($request, 'product-import-mutation')));
    }

    private function rateLimitKey(Request $request, string $scope): string
    {
        return $scope.'|'.($request->user()?->id ?: $request->ip());
    }

    private function chunkRateLimitKey(Request $request, string $scope): string
    {
        $importRun = $request->route('importRun');
        $importRunKey = is_object($importRun) && method_exists($importRun, 'getKey')
            ? $importRun->getKey()
            : $importRun;

        return $this->rateLimitKey($request, $scope).'|'.($importRunKey ?: 'unknown');
    }
}
