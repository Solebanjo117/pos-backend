<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CashMovementService
{
    /** @param array<string, mixed> $data */
    public function record(User $user, int $warehouseId, array $data): CashMovement
    {
        return DB::transaction(function () use ($user, $warehouseId, $data): CashMovement {
            $session = PosSession::query()
                ->where('user_id', $user->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new \InvalidArgumentException('Debe tener una sesión de caja abierta en esta sucursal para procesar el movimiento.');
            }

            $idempotencyKey = $data['idempotency_key'] ?? null;
            $requestHash = $idempotencyKey ? $this->requestHash($user, $warehouseId, $data) : null;
            if ($idempotencyKey) {
                $existing = CashMovement::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->request_hash, (string) $requestHash)) {
                        throw new \InvalidArgumentException('La clave de idempotencia ya fue utilizada con datos diferentes.');
                    }

                    return $existing;
                }
            }

            $amount = round((float) $data['amount'], 2);
            $in = (float) $session->cashMovements()->where('type', 'in')->sum('amount');
            $out = (float) $session->cashMovements()->where('type', 'out')->sum('amount');
            $balanceBefore = round((float) $session->opening_amount + $in - $out, 2);
            if ($data['type'] === 'out' && $amount > $balanceBefore) {
                throw new \InvalidArgumentException('La caja no tiene saldo suficiente para realizar esta salida.');
            }

            try {
                return CashMovement::query()->create([
                    'pos_session_id' => $session->id,
                    'user_id' => $user->id,
                    'type' => $data['type'],
                    'subtype' => $data['subtype'],
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => round($balanceBefore + ($data['type'] === 'in' ? $amount : -$amount), 2),
                    'reason' => $data['reason'],
                    'reference_number' => $data['reference_number'] ?? null,
                    'payment_method' => 'cash',
                    'business_datetime' => $data['business_datetime'] ?? now(),
                    'notes' => $data['notes'] ?? null,
                    'source_type' => $data['source_type'] ?? null,
                    'source_id' => $data['source_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);
            } catch (QueryException $exception) {
                if (! $idempotencyKey || ! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                    throw $exception;
                }

                $existing = CashMovement::query()->where('idempotency_key', $idempotencyKey)->first();
                if (! $existing || ! hash_equals((string) $existing->request_hash, (string) $requestHash)) {
                    throw new \InvalidArgumentException('La clave de idempotencia ya fue utilizada con datos diferentes.');
                }

                return $existing;
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function requestHash(User $user, int $warehouseId, array $data): string
    {
        return hash('sha256', json_encode([
            'user_id' => $user->id,
            'warehouse_id' => $warehouseId,
            'type' => $data['type'],
            'subtype' => $data['subtype'],
            'amount' => number_format(round((float) $data['amount'], 2), 2, '.', ''),
            'reason' => trim((string) $data['reason']),
            'reference_number' => $data['reference_number'] ?? null,
            'business_datetime' => $data['business_datetime'] ?? null,
            'notes' => $data['notes'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }
}
