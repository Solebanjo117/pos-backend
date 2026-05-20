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
            $warehouseId = $data['warehouse_id'] ?? Auth::user()?->current_warehouse_id;
            $session = $this->openSessionForWarehouse($warehouseId);

            if (! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar gastos operativos.');
            }

            $data['warehouse_id'] = $warehouseId;
            $cashMovement = $this->createCashMovementIfNeeded($data, $session);

            return OperationalExpense::create([
                ...$data,
                'cash_movement_id' => $cashMovement?->id,
                'pos_session_id' => $session->id,
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
            $expense->loadMissing(['cashMovement.posSession', 'posSession']);
            $merged = array_merge($expense->only([
                'warehouse_id',
                'pos_session_id',
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

            if ($expense->pos_session_id || $expense->cash_movement_id) {
                $this->ensureLinkedSessionIsOpen($expense);
            }

            $warehouseId = $merged['warehouse_id'] ?? Auth::user()?->current_warehouse_id;
            $session = $this->openSessionForWarehouse($warehouseId);

            if (! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar gastos operativos.');
            }

            if ($isCash && $expense->cashMovement) {
                $expense->cashMovement->update([
                    ...$this->cashMovementAttributes($merged),
                    'pos_session_id' => $session->id,
                ]);
                $data['cash_movement_id'] = $expense->cashMovement->id;
            } elseif ($isCash) {
                $cashMovement = $this->createCashMovementIfNeeded([...$merged, 'warehouse_id' => $warehouseId], $session);
                $data['cash_movement_id'] = $cashMovement?->id;
            } elseif ($wasCash) {
                $expense->cashMovement?->delete();
                $data['cash_movement_id'] = null;
            }

            $data['warehouse_id'] = $warehouseId;
            $data['pos_session_id'] = $session->id;

            $expense->update($data);

            return $expense->fresh(['warehouse', 'creator', 'cashMovement', 'posSession']);
        });
    }

    public function delete(OperationalExpense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $expense->loadMissing(['cashMovement.posSession', 'posSession']);

            $this->ensureLinkedSessionIsOpen($expense);
            $expense->cashMovement?->delete();

            $expense->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCashMovementIfNeeded(array $data, PosSession $session): ?CashMovement
    {
        if (($data['payment_method'] ?? null) !== 'cash') {
            return null;
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

    private function ensureLinkedSessionIsOpen(OperationalExpense $expense): void
    {
        if ($expense->posSession && $expense->posSession->status !== 'open') {
            throw new \InvalidArgumentException('No se puede modificar un gasto ligado a una caja cerrada.');
        }

        if ($expense->cashMovement?->posSession && $expense->cashMovement->posSession->status !== 'open') {
            throw new \InvalidArgumentException('No se puede modificar un gasto ligado a una caja cerrada.');
        }
    }
}
