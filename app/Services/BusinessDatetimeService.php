<?php

namespace App\Services;

use App\Models\AccountPaymentBatch;
use App\Models\BusinessDatetimeAudit;
use App\Models\CapitalMovement;
use App\Models\InventoryMovement;
use App\Models\OperationalExpense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessDatetimeService
{
    private const RECORD_TYPES = [
        'sales_order' => SalesOrder::class,
        'purchase_order' => PurchaseOrder::class,
        'sales_order_payment' => SalesOrderPayment::class,
        'purchase_order_payment' => PurchaseOrderPayment::class,
        'account_payment_batch' => AccountPaymentBatch::class,
        'inventory_movement' => InventoryMovement::class,
        'operational_expense' => OperationalExpense::class,
        'capital_movement' => CapitalMovement::class,
    ];

    public function allowedRecordTypes(): array
    {
        return array_keys(self::RECORD_TYPES);
    }

    public function findRecord(string $recordType, int $recordId): Model
    {
        $modelClass = self::RECORD_TYPES[$recordType] ?? null;

        if ($modelClass === null) {
            throw ValidationException::withMessages([
                'record_type' => 'Este tipo de registro no permite fecha/hora operativa.',
            ]);
        }

        return $modelClass::query()->findOrFail($recordId);
    }

    public function update(string $recordType, int $recordId, string $businessDatetime, string $reason, User $user): array
    {
        return DB::transaction(function () use ($recordType, $recordId, $businessDatetime, $reason, $user): array {
            $record = $this->findRecord($recordType, $recordId);
            $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEditable($recordType, $record);

            $previous = $record->business_datetime ? Carbon::parse($record->business_datetime) : null;
            $next = Carbon::parse($businessDatetime)->seconds(0);

            $record->business_datetime = $next;
            $record->save();

            $audit = BusinessDatetimeAudit::create([
                'record_type' => $recordType,
                'record_id' => $record->getKey(),
                'old_business_datetime' => $previous,
                'new_business_datetime' => $next,
                'reason' => $reason,
                'changed_by' => $user->id,
                'changed_at' => now(),
            ])->load('changedBy:id,name');

            return [
                'record_type' => $recordType,
                'record_id' => $record->getKey(),
                'business_datetime' => $record->business_datetime?->toIso8601String(),
                'created_at' => $record->created_at?->toIso8601String(),
                'latest_audit' => $this->serializeAudit($audit),
            ];
        });
    }

    public function detail(string $recordType, int $recordId): array
    {
        $record = $this->findRecord($recordType, $recordId);

        return [
            'record_type' => $recordType,
            'record_id' => $record->getKey(),
            'business_datetime' => $record->business_datetime?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
            'audits' => $this->audits($recordType, $recordId),
        ];
    }

    public function audits(string $recordType, int $recordId): array
    {
        return BusinessDatetimeAudit::query()
            ->with('changedBy:id,name')
            ->where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->latest('changed_at')
            ->limit(20)
            ->get()
            ->map(fn (BusinessDatetimeAudit $audit): array => $this->serializeAudit($audit))
            ->values()
            ->all();
    }

    private function assertEditable(string $recordType, Model $record): void
    {
        if ($record instanceof SalesOrder) {
            $record->loadMissing('posSession');

            if ($record->status === 'cancelled') {
                $this->blocked('No se puede editar la fecha/hora operativa de una venta cancelada.');
            }

            if ($record->posSession?->closed_at !== null) {
                $this->blocked('No se puede editar una venta asociada a un cierre de caja cerrado.');
            }
        }

        if ($record instanceof SalesOrderPayment) {
            $record->loadMissing('salesOrder.posSession');

            if ($record->salesOrder?->posSession?->closed_at !== null) {
                $this->blocked('No se puede editar un pago asociado a un cierre de caja cerrado.');
            }
        }

        if ($record instanceof PurchaseOrder) {
            if ($record->status === 'cancelled') {
                $this->blocked('No se puede editar la fecha/hora operativa de una compra cancelada.');
            }
        }

        if ($record instanceof PurchaseOrderPayment) {
            $record->loadMissing('posSession', 'purchaseOrder');

            if ($record->purchaseOrder?->status === 'cancelled') {
                $this->blocked('No se puede editar un pago de compra cancelada.');
            }

            if ($record->posSession?->closed_at !== null) {
                $this->blocked('No se puede editar un pago asociado a un cierre de caja cerrado.');
            }
        }

        if ($record instanceof AccountPaymentBatch) {
            $record->loadMissing('posSession');

            if ($record->posSession?->closed_at !== null) {
                $this->blocked('No se puede editar un lote de pago asociado a un cierre de caja cerrado.');
            }
        }

        if ($record instanceof OperationalExpense) {
            $record->loadMissing('posSession');

            if ($record->posSession?->closed_at !== null) {
                $this->blocked('No se puede editar un gasto asociado a un cierre de caja cerrado.');
            }
        }

        // Inventory movements, financial adjustments and capital movements do not
        // currently have a closed/reconciled flag in this codebase.
        unset($recordType);
    }

    private function blocked(string $message): void
    {
        throw ValidationException::withMessages([
            'business_datetime' => $message,
        ]);
    }

    private function serializeAudit(BusinessDatetimeAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'old_business_datetime' => $audit->old_business_datetime?->toIso8601String(),
            'new_business_datetime' => $audit->new_business_datetime?->toIso8601String(),
            'reason' => $audit->reason,
            'changed_at' => $audit->changed_at?->toIso8601String(),
            'changed_by' => $audit->changedBy ? [
                'id' => $audit->changedBy->id,
                'name' => $audit->changedBy->name,
            ] : null,
        ];
    }
}
