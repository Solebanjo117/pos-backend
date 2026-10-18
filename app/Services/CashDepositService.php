<?php

namespace App\Services;

use App\Models\AccountPaymentBatch;
use App\Models\CashDepositAllocation;
use App\Models\FinancialTransfer;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashDepositService
{
    public function __construct(private CashPaymentQueryService $payments, private FinancialTransferService $transfers) {}

    public function sources(array $filters, User $user): array
    {
        $warehouseId = (int) $filters['warehouse_id'];
        $this->authorizeWarehouse($user, $warehouseId);
        $query = $this->sourceQuery($warehouseId, $filters);
        $totals = (clone $query)->select([])->selectRaw('SUM(movements.amount) as collected, SUM(COALESCE(assigned.deposited, 0)) as deposited')->first();
        $legacyCutoff = $this->unassignedDepositCutoff($warehouseId);
        $blocked = $legacyCutoff ? (clone $query)->where('movements.business_datetime', '<=', $legacyCutoff)
            ->select([])->selectRaw('SUM(movements.amount - COALESCE(assigned.deposited, 0)) as remaining')->first()->remaining : 0;
        $page = $query->orderBy('movements.business_datetime')->orderBy('movements.scope')->orderBy('movements.id')
            ->paginate((int) ($filters['per_page'] ?? 100));

        return [
            'data' => collect($page->items())->map(fn ($row) => [
                ...$this->payments->normalizeRow($row),
                'requires_reconciliation' => $legacyCutoff !== null && Carbon::parse($row->business_datetime)->lte($legacyCutoff),
            ])->all(),
            'summary' => ['collected' => round((float) $totals->collected, 2), 'deposited' => round((float) $totals->deposited, 2),
                'remaining' => round((float) $totals->collected - (float) $totals->deposited, 2),
                'blocked_remaining' => round((float) $blocked, 2),
                'available_for_deposit' => round((float) $totals->collected - (float) $totals->deposited - (float) $blocked, 2)],
            'availability' => app(WarehouseCashService::class)->availability($warehouseId),
            'currency' => \App\Models\Setting::get('store_currency', 'USD'),
            'warehouse' => Warehouse::query()->findOrFail($warehouseId)->only('id', 'name'),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'warnings' => $legacyCutoff ? ['Hay depósitos anteriores sin asignación a cobros. Los cobros efectivos hasta '.$legacyCutoff->toISOString().' requieren conciliación y no pueden seleccionarse; no se presume que estén libres.'] : [],
        ];
    }

    /** @return array{transfer: FinancialTransfer, replayed: bool} */
    public function create(array $data, User $user): array
    {
        $warehouseId = (int) $data['warehouse_id'];
        $this->authorizeWarehouse($user, $warehouseId);
        $allocations = collect($data['allocations'])->map(fn ($item) => [
            'scope' => (string) $item['scope'], 'id' => (int) $item['id'], 'amount' => $this->cents($item['amount']) / 100,
        ])->sortBy(fn ($item) => $item['scope'].':'.str_pad((string) $item['id'], 20, '0', STR_PAD_LEFT))->values();
        $transferData = [
            'idempotency_key' => $data['idempotency_key'], 'from_endpoint_type' => 'warehouse_cash', 'from_endpoint_id' => $warehouseId,
            'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => (int) $data['financial_account_id'],
            'amount' => $this->cents($data['amount']) / 100,
            'collection_date_from' => $data['date_from'], 'collection_date_to' => $data['date_to'],
            'business_datetime' => $data['business_datetime'] ?? null,
            'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'deposit_allocations' => $allocations->all(),
        ];

        return DB::transaction(function () use ($data, $user, $warehouseId, $allocations, $transferData): array {
            Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
            // Replays must be checked before validating balances/remnants depleted by the original request.
            if (FinancialTransfer::query()->where('idempotency_key', $data['idempotency_key'])->exists()) {
                return $this->transfers->create($transferData, $user);
            }
            if ($allocations->isEmpty() || $allocations->count() > 500 || $allocations->unique(fn ($item) => $item['scope'].':'.$item['id'])->count() !== $allocations->count()) {
                throw new InvalidArgumentException('Seleccione cobros distintos para el depósito.');
            }
            if ($allocations->sum(fn ($item) => $this->cents($item['amount'])) !== $this->cents($data['amount'])) {
                throw new InvalidArgumentException('El monto debe coincidir con la suma de las asignaciones.');
            }
            $effectiveAt = $user->isAdmin() && ! empty($data['business_datetime']) ? Carbon::parse($data['business_datetime']) : now();
            $legacyCutoff = $this->unassignedDepositCutoff($warehouseId);
            foreach ($allocations as $item) {
                if ($this->cents($item['amount']) === 0) {
                    throw new InvalidArgumentException('Cada asignación debe ser mayor a cero.');
                }
                $model = match ($item['scope']) {
                    'sales' => SalesOrderPayment::class,
                    'receipts' => AccountPaymentBatch::class,
                    default => throw new InvalidArgumentException('La fuente del cobro no es válida.'),
                };
                $model::query()->whereKey($item['id'])->lockForUpdate()->firstOrFail();
                $source = $this->sourceQuery($warehouseId, $data)->where('movements.scope', $item['scope'])->where('movements.id', $item['id'])->first();
                if (! $source || Carbon::parse($source->business_datetime)->gt($effectiveAt)) {
                    throw new InvalidArgumentException('El cobro debe pertenecer a la sucursal y al rango, ser efectivo y existir en la fecha del depósito.');
                }
                if ($legacyCutoff && Carbon::parse($source->business_datetime)->lte($legacyCutoff)) {
                    throw new InvalidArgumentException('Este cobro requiere conciliación de depósitos anteriores sin asignación; no se puede considerar libre para depositar.');
                }
                if ($this->cents($item['amount']) > $this->cents($source->remaining)) {
                    throw new InvalidArgumentException('Un cobro ya fue depositado total o parcialmente. Actualice los pendientes.');
                }
            }
            $result = $this->transfers->create($transferData, $user);
            if (! $result['replayed']) {
                foreach ($allocations as $item) {
                    CashDepositAllocation::query()->create(['financial_transfer_id' => $result['transfer']->id, 'warehouse_id' => $warehouseId,
                        'source_scope' => $item['scope'], 'source_id' => $item['id'], 'amount' => $item['amount']]);
                }
            }
            $result['transfer']->load('depositAllocations');

            return $result;
        }, 3);
    }

    private function sourceQuery(int $warehouseId, array $filters): Builder
    {
        $assigned = CashDepositAllocation::query()->whereNull('released_at')->selectRaw('source_scope, source_id, SUM(amount) as deposited')->groupBy('source_scope', 'source_id');

        return $this->payments->query($warehouseId, ['scope' => 'sales', 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to']])
            ->where('movements.liquidity_bucket', 'cash')->where('movements.amount', '>', 0)
            ->leftJoinSub($assigned, 'assigned', fn ($join) => $join->on('assigned.source_scope', '=', 'movements.scope')->on('assigned.source_id', '=', 'movements.id'))
            ->selectRaw('COALESCE(assigned.deposited, 0) as deposited, movements.amount - COALESCE(assigned.deposited, 0) as remaining');
    }

    private function authorizeWarehouse(User $user, int $warehouseId): void
    {
        abort_unless($user->hasRole(['admin', 'manager']) && ($user->isAdmin() || $user->getEffectiveWarehouseId() === $warehouseId), 403);
    }

    private function unassignedDepositCutoff(int $warehouseId): ?Carbon
    {
        $cutoff = FinancialTransfer::query()->where('to_endpoint_type', FinancialTransfer::ENDPOINT_FINANCIAL_ACCOUNT)
            ->whereIn('to_endpoint_id', \App\Models\FinancialAccount::query()->where('type', 'bank')->select('id'))
            ->where(function ($query) use ($warehouseId): void {
                $query->where(fn ($cash) => $cash->where('from_endpoint_type', 'warehouse_cash')->where('from_endpoint_id', $warehouseId))
                    ->orWhere(fn ($session) => $session->where('from_endpoint_type', FinancialTransfer::ENDPOINT_POS_SESSION)
                        ->whereIn('from_endpoint_id', \App\Models\PosSession::query()->where('warehouse_id', $warehouseId)->select('id')));
            })->where('status', FinancialTransfer::STATUS_COMPLETED)->whereNull('reversal_of_id')
            ->whereDoesntHave('depositAllocations')->selectRaw('MAX(COALESCE(business_datetime, created_at)) as effective_at')->value('effective_at');

        return $cutoff ? Carbon::parse($cutoff) : null;
    }

    private function cents(mixed $amount): int
    {
        $value = (float) $amount;
        if (! is_finite($value) || $value < 0 || abs($value - round($value, 2)) > 0.000001) {
            throw new InvalidArgumentException('Los montos deben tener como máximo dos decimales.');
        }

        return (int) round($value * 100);
    }
}
