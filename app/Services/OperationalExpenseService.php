<?php

namespace App\Services;

use App\Models\CashierQueueEntry;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseBeneficiary;
use App\Models\OperationalExpenseCategory;
use App\Models\OperationalExpenseEvent;
use App\Models\PosSession;
use App\Models\Supplier;
use App\Models\User;
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
            $expense = OperationalExpense::create([
                ...$data,
                'created_by' => Auth::id(),
            ]);

            $cashMovement = $session ? $this->createCashMovementIfNeeded($data, $session, $expense) : null;
            if ($cashMovement) {
                $expense->update([
                    'cash_movement_id' => $cashMovement->id,
                    'pos_session_id' => $session?->id,
                ]);
            }

            $this->ledgerPostingService->postOperationalExpense($expense->fresh([
                'categoryRecord.defaultLedgerAccount',
                'ledgerAccount',
                'supplier',
                'customer',
                'beneficiary',
            ]));
            $this->recordEvent($expense, 'created', null, $expense->status, null, [], $this->eventSnapshot($expense));

            return $expense->fresh([
                'warehouse',
                'creator',
                'cashMovement',
                'posSession',
                'categoryRecord',
                'supplier',
                'customer',
                'beneficiary',
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
            if (! in_array($expense->status, ['draft', 'pending', 'scheduled'], true)) {
                throw new \InvalidArgumentException(match ($expense->status) {
                    'queued' => 'El gasto esta en Caja y no puede editarse. Cancele la entrada de Caja antes de corregirlo.',
                    'paid' => 'Un gasto pagado solo puede reversarse.',
                    default => 'Este gasto es de solo lectura y no puede editarse.',
                });
            }

            $before = $this->eventSnapshot($expense);
            $previousStatus = $expense->status;

            if ($expense->status === 'paid' && $this->changesFinancialFields($expense, $data)) {
                throw new \InvalidArgumentException('Un gasto pagado no se edita directamente. Reverselo y registre la correccion para conservar la auditoria.');
            }

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
                $cashMovement = $this->createCashMovementIfNeeded([...$merged, 'warehouse_id' => $warehouseId], $session, $expense);
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
                'customer',
                'beneficiary',
            ]));
            $this->recordEvent($expense, 'updated', $previousStatus, $expense->status, $data['change_reason'] ?? null, $before, $this->eventSnapshot($expense));

            return $expense->fresh([
                'warehouse',
                'creator',
                'cashMovement',
                'posSession',
                'categoryRecord',
                'supplier',
                'customer',
                'beneficiary',
                'ledgerAccount',
            ]);
        });
    }

    public function delete(OperationalExpense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            if (in_array($expense->status, ['cancelled', 'reversed'], true)) {
                throw new \InvalidArgumentException('Este gasto ya fue cerrado y es de solo lectura.');
            }

            $before = $this->eventSnapshot($expense);
            $expense->loadMissing(['cashMovement.posSession', 'posSession']);

            if ($expense->cash_movement_id || $expense->pos_session_id) {
                $this->ensureLinkedSessionIsOpen($expense);
            }

            $targetStatus = $expense->status === 'paid' ? 'reversed' : 'cancelled';
            if ($expense->status === 'queued') {
                app(CashierQueueService::class)->cancelForSource(OperationalExpense::class, (int) $expense->id, Auth::user());
            }
            $this->ledgerPostingService->reverseSource('operational_expense_payment', (int) $expense->id, Auth::id(), 'Reversa de pago de gasto.');
            app(OperationalPaymentService::class)->reverse($expense, Auth::user());
            $this->ledgerPostingService->reverseSource('operational_expense', (int) $expense->id, Auth::id(), 'Reversa de gasto operativo.');

            if ($expense->cashMovement) {
                $session = $expense->cashMovement->posSession;
                $balanceBefore = $this->sessionCashBalance($session);
                CashMovement::create([
                    'pos_session_id' => $session->id,
                    'user_id' => Auth::id(),
                    'type' => 'in',
                    'subtype' => 'operational_expense_reversal',
                    'amount' => $expense->cashMovement->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => round($balanceBefore + (float) $expense->cashMovement->amount, 2),
                    'reason' => 'Reversa de gasto: '.($expense->description ?: $expense->category),
                    'reference_number' => $expense->reference_number,
                    'payment_method' => 'cash',
                    'notes' => 'Compensa el movimiento de salida #'.$expense->cashMovement->id.'.',
                    'source_type' => 'operational_expense_reversal',
                    'source_id' => $expense->id,
                    'business_datetime' => now(),
                ]);
            }

            $expense->update([
                'status' => $targetStatus,
                $targetStatus === 'reversed' ? 'reversed_at' : 'cancelled_at' => now(),
            ]);
            $this->recordEvent($expense, $targetStatus, $before['status'] ?? null, $targetStatus, 'Accion solicitada desde Gastos Operativos.', $before, $this->eventSnapshot($expense));
        });
    }

    /** @param array<string, mixed> $data */
    public function settleFromQueue(OperationalExpense $expense, CashierQueueEntry $entry, array $data, User $user): OperationalExpense
    {
        return DB::transaction(function () use ($expense, $data, $user): OperationalExpense {
            $expense = OperationalExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if (! in_array($expense->status, ['pending', 'scheduled', 'queued'], true)) {
                throw new \InvalidArgumentException('Este gasto ya no esta disponible para pago.');
            }

            $before = $this->eventSnapshot($expense);
            $finalAmount = round((float) ($data['final_amount'] ?? $expense->estimated_amount ?? $expense->amount), 2);
            $estimatedAmount = round((float) ($expense->estimated_amount ?? $expense->amount), 2);
            $variance = round($finalAmount - $estimatedAmount, 2);
            if ($finalAmount <= 0) {
                throw new \InvalidArgumentException('El monto final debe ser mayor a cero.');
            }
            if (abs($variance) > 0.005 && trim((string) ($data['variance_reason'] ?? '')) === '') {
                throw new \InvalidArgumentException('Explique la diferencia entre el monto estimado y el monto final.');
            }

            $allocations = null;
            if (isset($data['payments'])) {
                $allocations = app(OperationalPaymentService::class)->normalize($data['payments'], $finalAmount, \App\Models\PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS);
                $data['payment_method'] = count($allocations) === 1 ? $allocations[0]['method'] : 'mixed';
            }

            $method = (string) ($data['payment_method'] ?? '');
            $session = $this->openSessionForUserAndWarehouse($user, $expense->warehouse_id);
            if ($method === 'cash' && ! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta en esta sucursal para pagar en efectivo.');
            }

            if ($allocations !== null) {
                app(OperationalPaymentService::class)->record($expense, $allocations, 'out', $user);
            }

            $expense->fill([
                'amount' => $finalAmount,
                'final_amount' => $finalAmount,
                'variance_amount' => $variance,
                'variance_reason' => $data['variance_reason'] ?? null,
                'payment_method' => $method,
                'pos_session_id' => $session?->id,
                'reference_number' => $data['reference_number'] ?? null,
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            if ($allocations === null && $method === 'cash' && $session) {
                $movement = $this->createCashMovementIfNeeded($this->currentExpenseData($expense), $session, $expense, $user->id);
                $expense->update(['cash_movement_id' => $movement?->id, 'pos_session_id' => $session->id]);
            }

            $this->ledgerPostingService->postOperationalExpense($expense->fresh(['categoryRecord.defaultLedgerAccount', 'ledgerAccount', 'supplier', 'customer', 'beneficiary']), $user->id);
            $this->recordEvent($expense, 'paid', $before['status'] ?? null, 'paid', $data['variance_reason'] ?? null, $before, $this->eventSnapshot($expense), $user->id);

            return $expense->fresh(['warehouse', 'creator', 'cashMovement', 'categoryRecord', 'supplier', 'customer', 'beneficiary', 'ledgerAccount', 'events.user']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeExpenseData(array $data, ?OperationalExpense $existing = null): array
    {
        $data = $this->normalizeParty($data, $existing);
        $category = $this->resolveCategory($data, $existing);
        $status = $data['status'] ?? $existing?->status ?? 'paid';

        $data['category_id'] = $category->id;
        $data['category'] = $category->slug;
        $data['subcategory'] = $data['subcategory'] ?? $category->subcategory;
        $data['recurrence'] = $data['recurrence'] ?? $existing?->recurrence ?? 'one_time';
        $data['status'] = $status;
        $data['estimated_amount'] = $data['estimated_amount'] ?? $existing?->estimated_amount ?? $data['amount'] ?? $existing?->amount;
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
            $data['final_amount'] = $data['final_amount'] ?? $data['amount'] ?? $existing?->amount;
        } else {
            $data['planned_payment_method'] = $data['planned_payment_method'] ?? $data['payment_method'] ?? $existing?->planned_payment_method;
            $data['payment_method'] = null;
            $data['paid_at'] = null;
        }

        unset($data['custom_category_name']);
        unset($data['party_type'], $data['party_id']);
        unset($data['change_reason']);

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
            'customer_id' => $expense->customer_id,
            'beneficiary_id' => $expense->beneficiary_id,
            'ledger_account_id' => $expense->ledger_account_id,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'estimated_amount' => $expense->estimated_amount,
            'final_amount' => $expense->final_amount,
            'variance_amount' => $expense->variance_amount,
            'variance_reason' => $expense->variance_reason,
            'payment_method' => $expense->payment_method,
            'planned_payment_method' => $expense->planned_payment_method,
            'recurrence' => $expense->recurrence ?? 'one_time',
            'status' => $expense->status ?? 'paid',
            'paid_at' => $expense->paid_at,
            'due_at' => $expense->due_at,
            'queued_at' => $expense->queued_at,
            'affects_cash_flow' => $expense->affects_cash_flow,
            'affects_income_statement' => $expense->affects_income_statement,
            'expense_kind' => $expense->expense_kind ?? 'operational',
            'reference_number' => $expense->reference_number,
            'notes' => $expense->notes,
            'business_datetime' => $expense->business_datetime,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeParty(array $data, ?OperationalExpense $existing = null): array
    {
        if (array_key_exists('party_type', $data) || array_key_exists('party_id', $data)) {
            $type = $data['party_type'] ?? null;
            $id = $data['party_id'] ?? null;

            $data['customer_id'] = null;
            $data['supplier_id'] = null;
            $data['beneficiary_id'] = null;

            if ($type !== null || $id !== null) {
                if (! in_array($type, ['customer', 'supplier', 'beneficiary', 'staff'], true) || ! is_numeric($id)) {
                    throw new \InvalidArgumentException('Seleccione una persona o empresa valida.');
                }

                $model = match ($type) {
                    'customer' => Customer::query()->where('status', 'active')->find($id),
                    'supplier' => Supplier::query()->where('status', 'active')->find($id),
                    'beneficiary' => OperationalExpenseBeneficiary::query()->where('is_active', true)->find($id),
                    'staff' => User::query()->where('is_active', true)->find($id),
                };

                if (! $model) {
                    throw new \InvalidArgumentException('La persona o empresa seleccionada no esta disponible.');
                }

                if ($type === 'staff') {
                    $beneficiary = OperationalExpenseBeneficiary::query()->firstOrCreate(
                        ['user_id' => (int) $model->id],
                        [
                            'name' => $model->name,
                            'name_key' => OperationalExpenseBeneficiary::normalizeNameKey($model->name),
                            'beneficiary_kind' => in_array($model->role, ['seller', 'cashier', 'buyer', 'assistant'], true) ? 'employee' : 'staff',
                            'type' => 'individual',
                            'email' => $model->email,
                            'is_active' => true,
                        ],
                    );
                    $data['beneficiary_id'] = $beneficiary->id;
                } else {
                    $data[$type === 'beneficiary' ? 'beneficiary_id' : $type.'_id'] = (int) $id;
                }
            }
        } else {
            $data['customer_id'] = $data['customer_id'] ?? $existing?->customer_id;
            $data['supplier_id'] = $data['supplier_id'] ?? $existing?->supplier_id;
            $data['beneficiary_id'] = $data['beneficiary_id'] ?? $existing?->beneficiary_id;
        }

        $partyIds = array_filter([
            $data['customer_id'] ?? null,
            $data['supplier_id'] ?? null,
            $data['beneficiary_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (count($partyIds) > 1) {
            throw new \InvalidArgumentException('Un gasto solo puede relacionarse con una persona o empresa.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiresOpenCashSession(array $data): bool
    {
        return ($data['status'] ?? 'paid') === 'paid'
            && ($data['payment_method'] ?? null) === 'cash'
            && ($data['affects_cash_flow'] ?? true) !== false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCashMovementIfNeeded(array $data, PosSession $session, OperationalExpense $expense, ?int $userId = null): ?CashMovement
    {
        if (! $this->requiresOpenCashSession($data)) {
            return null;
        }

        $balanceBefore = $this->sessionCashBalance($session);
        $amount = round((float) ($data['amount'] ?? 0), 2);

        return CashMovement::create([
            ...$this->cashMovementAttributes($data),
            'pos_session_id' => $session->id,
            'user_id' => $userId ?? Auth::id(),
            'balance_before' => $balanceBefore,
            'balance_after' => round($balanceBefore - $amount, 2),
            'source_type' => 'operational_expense',
            'source_id' => $expense->id,
            'business_datetime' => $expense->business_datetime ?? now(),
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
            'subtype' => 'operational_expense',
            'amount' => round((float) ($data['amount'] ?? 0), 2),
            'reason' => 'Gasto operativo: '.$description,
            'reference_number' => $data['reference_number'] ?? null,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function openSessionForUserAndWarehouse(User $user, ?int $warehouseId): ?PosSession
    {
        if (! $warehouseId) {
            return null;
        }

        return PosSession::query()
            ->where('user_id', $user->id)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'open')
            ->latest()
            ->first();
    }

    private function sessionCashBalance(PosSession $session): float
    {
        $in = (float) $session->cashMovements()->where('type', 'in')->sum('amount');
        $out = (float) $session->cashMovements()->where('type', 'out')->sum('amount');

        return round((float) $session->opening_amount + $in - $out, 2);
    }

    /** @param array<string, mixed> $data */
    private function changesFinancialFields(OperationalExpense $expense, array $data): bool
    {
        foreach (['amount', 'payment_method', 'status', 'expense_date', 'category_id', 'ledger_account_id', 'warehouse_id', 'affects_cash_flow', 'affects_income_statement'] as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $expense->{$field}) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function eventSnapshot(OperationalExpense $expense): array
    {
        return collect($expense->only([
            'status', 'amount', 'estimated_amount', 'final_amount', 'variance_amount',
            'expense_date', 'due_at', 'category_id', 'supplier_id', 'customer_id',
            'beneficiary_id', 'payment_method', 'planned_payment_method', 'reference_number',
        ]))->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value)->all();
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function recordEvent(OperationalExpense $expense, string $eventType, ?string $fromStatus, ?string $toStatus, ?string $reason, array $before, array $after, ?int $userId = null): void
    {
        OperationalExpenseEvent::create([
            'operational_expense_id' => $expense->id,
            'user_id' => $userId ?? Auth::id(),
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
        ]);
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
