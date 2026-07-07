<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseCategory;
use App\Models\PosSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationalExpenseService
{
    public function __construct(
        private LedgerPostingService $ledgerPostingService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): OperationalExpense
    {
        return DB::transaction(function () use ($data): OperationalExpense {
            $data = $this->normalizeExpenseData($data);
            $warehouseId = $data['warehouse_id'] ?? Auth::user()?->current_warehouse_id;
            $session = $this->openSessionForWarehouse($warehouseId);

            if ($this->requiresOpenCashSession($data) && ! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar gastos en efectivo.');
            }

            $data['warehouse_id'] = $warehouseId;
            $cashMovement = $session ? $this->createCashMovementIfNeeded($data, $session) : null;

            $expense = OperationalExpense::create([
                ...$data,
                'cash_movement_id' => $cashMovement?->id,
                'pos_session_id' => $cashMovement ? $session?->id : null,
                'created_by' => Auth::id(),
            ]);

            $this->ledgerPostingService->postOperationalExpense($expense->fresh([
                'categoryRecord.defaultLedgerAccount',
                'ledgerAccount',
                'supplier',
            ]));

            return $expense->fresh([
                'warehouse',
                'creator',
                'cashMovement',
                'posSession',
                'categoryRecord',
                'supplier',
                'ledgerAccount',
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
            $merged = array_replace($this->currentExpenseData($expense), $data);
            $merged = $this->normalizeExpenseData($merged, $expense);

            $wasCash = $expense->payment_method === 'cash' && $expense->cashMovement;
            $isCash = $this->requiresOpenCashSession($merged);

            if ($wasCash || $expense->pos_session_id || $expense->cash_movement_id) {
                $this->ensureLinkedSessionIsOpen($expense);
            }

            $warehouseId = $merged['warehouse_id'] ?? Auth::user()?->current_warehouse_id;
            $session = $this->openSessionForWarehouse($warehouseId);

            if ($isCash && ! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar gastos en efectivo.');
            }

            if ($isCash && $expense->cashMovement && $session) {
                $expense->cashMovement->update([
                    ...$this->cashMovementAttributes($merged),
                    'pos_session_id' => $session->id,
                ]);
                $merged['cash_movement_id'] = $expense->cashMovement->id;
                $merged['pos_session_id'] = $session->id;
            } elseif ($isCash && $session) {
                $cashMovement = $this->createCashMovementIfNeeded([...$merged, 'warehouse_id' => $warehouseId], $session);
                $merged['cash_movement_id'] = $cashMovement?->id;
                $merged['pos_session_id'] = $cashMovement ? $session->id : null;
            } elseif ($wasCash) {
                $expense->cashMovement?->delete();
                $merged['cash_movement_id'] = null;
                $merged['pos_session_id'] = null;
            } else {
                $merged['cash_movement_id'] = null;
                $merged['pos_session_id'] = null;
            }

            $merged['warehouse_id'] = $warehouseId;

            $expense->update($merged);

            $this->ledgerPostingService->postOperationalExpense($expense->fresh([
                'categoryRecord.defaultLedgerAccount',
                'ledgerAccount',
                'supplier',
            ]));

            return $expense->fresh([
                'warehouse',
                'creator',
                'cashMovement',
                'posSession',
                'categoryRecord',
                'supplier',
                'ledgerAccount',
            ]);
        });
    }

    public function delete(OperationalExpense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $expense->loadMissing(['cashMovement.posSession', 'posSession']);

            if ($expense->cash_movement_id || $expense->pos_session_id) {
                $this->ensureLinkedSessionIsOpen($expense);
            }

            $this->ledgerPostingService->reverseSource(
                'operational_expense',
                (int) $expense->id,
                Auth::id(),
                'Reversa por eliminacion de gasto operativo.',
            );

            $expense->cashMovement?->delete();
            $expense->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeExpenseData(array $data, ?OperationalExpense $existing = null): array
    {
        $category = $this->resolveCategory($data, $existing);
        $status = $data['status'] ?? $existing?->status ?? 'paid';

        $data['category_id'] = $category->id;
        $data['category'] = $category->slug;
        $data['subcategory'] = $data['subcategory'] ?? $category->subcategory;
        $data['recurrence'] = $data['recurrence'] ?? $existing?->recurrence ?? 'one_time';
        $data['status'] = $status;
        $data['expense_kind'] = $data['expense_kind'] ?? $category->expense_kind ?? 'operational';
        $data['affects_cash_flow'] = array_key_exists('affects_cash_flow', $data)
            ? (bool) $data['affects_cash_flow']
            : (bool) ($existing?->affects_cash_flow ?? $category->affects_cash_flow ?? true);
        $data['affects_income_statement'] = array_key_exists('affects_income_statement', $data)
            ? (bool) $data['affects_income_statement']
            : (bool) ($existing?->affects_income_statement ?? $category->affects_income_statement ?? true);

        if ($status === 'paid') {
            if (empty($data['payment_method'])) {
                throw new \InvalidArgumentException('El metodo de pago es obligatorio para gastos pagados.');
            }

            $data['paid_at'] = $data['paid_at'] ?? $existing?->paid_at ?? now();
        } else {
            $data['payment_method'] = null;
            $data['paid_at'] = null;
        }

        unset($data['custom_category_name']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCategory(array $data, ?OperationalExpense $existing = null): OperationalExpenseCategory
    {
        if (! empty($data['category_id'])) {
            return OperationalExpenseCategory::query()->findOrFail($data['category_id']);
        }

        $name = trim((string) ($data['custom_category_name'] ?? ''));
        if ($name !== '') {
            $slug = Str::slug($name) ?: 'gasto';

            return OperationalExpenseCategory::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'expense_kind' => 'operational',
                    'affects_cash_flow' => true,
                    'affects_income_statement' => true,
                    'is_active' => true,
                ],
            );
        }

        $slug = Str::slug((string) ($data['category'] ?? $existing?->category ?? 'other')) ?: 'other';

        return OperationalExpenseCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => OperationalExpense::CATEGORIES[$slug] ?? Str::headline(str_replace('-', ' ', $slug)),
                'expense_kind' => 'operational',
                'affects_cash_flow' => true,
                'affects_income_statement' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function currentExpenseData(OperationalExpense $expense): array
    {
        return [
            'warehouse_id' => $expense->warehouse_id,
            'cash_movement_id' => $expense->cash_movement_id,
            'pos_session_id' => $expense->pos_session_id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'category' => $expense->category,
            'category_id' => $expense->category_id,
            'subcategory' => $expense->subcategory,
            'supplier_id' => $expense->supplier_id,
            'ledger_account_id' => $expense->ledger_account_id,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'payment_method' => $expense->payment_method,
            'recurrence' => $expense->recurrence ?? 'one_time',
            'status' => $expense->status ?? 'paid',
            'paid_at' => $expense->paid_at,
            'affects_cash_flow' => $expense->affects_cash_flow,
            'affects_income_statement' => $expense->affects_income_statement,
            'expense_kind' => $expense->expense_kind ?? 'operational',
            'reference_number' => $expense->reference_number,
            'notes' => $expense->notes,
            'business_datetime' => $expense->business_datetime,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiresOpenCashSession(array $data): bool
    {
        return ($data['status'] ?? 'paid') === 'paid' && ($data['payment_method'] ?? null) === 'cash';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCashMovementIfNeeded(array $data, PosSession $session): ?CashMovement
    {
        if (! $this->requiresOpenCashSession($data)) {
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
        $description = $data['description'] ?? $data['category'] ?? 'Gasto operativo';

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
