<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\OperationalExpense;
use App\Models\PosSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OperationalExpenseService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): OperationalExpense
    {
        return DB::transaction(function () use ($data): OperationalExpense {
            $cashMovement = $this->createCashMovementIfNeeded($data);

            return OperationalExpense::create([
                ...$data,
                'warehouse_id' => $data['warehouse_id'] ?? Auth::user()?->current_warehouse_id,
                'cash_movement_id' => $cashMovement?->id,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OperationalExpense $expense, array $data): OperationalExpense
    {
        return DB::transaction(function () use ($expense, $data): OperationalExpense {
            $expense->loadMissing('cashMovement.posSession');
            $merged = array_merge($expense->only([
                'warehouse_id',
                'expense_date',
                'category',
                'description',
                'amount',
                'payment_method',
                'reference_number',
                'notes',
            ]), $data);

            $wasCash = $expense->payment_method === 'cash' && $expense->cashMovement;
            $isCash = ($merged['payment_method'] ?? null) === 'cash';

            if ($wasCash) {
                $this->ensureCashMovementCanChange($expense);
            }

            if ($isCash && $expense->cashMovement) {
                $expense->cashMovement->update($this->cashMovementAttributes($merged));
                $data['cash_movement_id'] = $expense->cashMovement->id;
            } elseif ($isCash) {
                $cashMovement = $this->createCashMovementIfNeeded($merged);
                $data['cash_movement_id'] = $cashMovement?->id;
                $data['warehouse_id'] = $merged['warehouse_id'] ?? Auth::user()?->current_warehouse_id;
            } elseif ($wasCash) {
                $expense->cashMovement?->delete();
                $data['cash_movement_id'] = null;
            }

            $expense->update($data);

            return $expense->fresh(['warehouse', 'creator', 'cashMovement']);
        });
    }

    public function delete(OperationalExpense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $expense->loadMissing('cashMovement.posSession');

            if ($expense->cashMovement) {
                $this->ensureCashMovementCanChange($expense);
                $expense->cashMovement->delete();
            }

            $expense->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCashMovementIfNeeded(array $data): ?CashMovement
    {
        if (($data['payment_method'] ?? null) !== 'cash') {
            return null;
        }

        $session = $this->openSessionForWarehouse($data['warehouse_id'] ?? Auth::user()?->current_warehouse_id);

        if (! $session) {
            throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar gastos en efectivo.');
        }

        return CashMovement::create([
            ...$this->cashMovementAttributes($data),
            'pos_session_id' => $session->id,
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cashMovementAttributes(array $data): array
    {
        $description = $data['description'] ?? OperationalExpense::CATEGORIES[$data['category'] ?? 'other'] ?? 'Gasto operativo';

        return [
            'type' => 'out',
            'amount' => round((float) ($data['amount'] ?? 0), 2),
            'reason' => 'Gasto operativo: '.$description,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function openSessionForWarehouse(?int $warehouseId): ?PosSession
    {
        if (! $warehouseId) {
            return null;
        }

        return PosSession::query()
            ->where('user_id', Auth::id())
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'open')
            ->latest()
            ->first();
    }

    private function ensureCashMovementCanChange(OperationalExpense $expense): void
    {
        if ($expense->cashMovement?->posSession && $expense->cashMovement->posSession->status !== 'open') {
            throw new \InvalidArgumentException('No se puede modificar un gasto ligado a una caja cerrada.');
        }
    }
}
