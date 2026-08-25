<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\PosSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashMovementController extends Controller
{
    /**
     * Store a new cash movement for the current open session.
     */
    public function store(Request $request)
    {
        $userId = auth()->id();

        if (! $userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la petición.'], 401);
        }

        $validated = $request->validate([
            'type' => 'required|in:in,out',
            'subtype' => 'nullable|in:cash_in,cash_out,deposit,withdrawal,adjustment_positive,adjustment_negative',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:120',
            'business_datetime' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'nullable|uuid',
        ]);

        $subtype = $request->input('subtype', $request->type === 'in' ? 'cash_in' : 'cash_out');
        $expectedType = in_array($subtype, ['cash_in', 'withdrawal', 'adjustment_positive'], true) ? 'in' : 'out';
        if ($request->type !== $expectedType) {
            return response()->json(['message' => 'El tipo no coincide con la operacion seleccionada.'], 422);
        }
        if (str_starts_with($subtype, 'adjustment_') && auth()->user()?->role !== 'admin') {
            return response()->json(['message' => 'Solo un administrador puede ajustar el saldo de Caja.'], 403);
        }

        $warehouseId = $request->integer('warehouse_id');
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $requestHash = hash('sha256', json_encode([
            'user_id' => $userId,
            'warehouse_id' => $warehouseId,
            'type' => $validated['type'],
            'subtype' => $subtype,
            'amount' => round((float) $validated['amount'], 2),
            'reason' => trim($validated['reason']),
            'reference_number' => $validated['reference_number'] ?? null,
            'business_datetime' => $validated['business_datetime'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], JSON_THROW_ON_ERROR));

        try {
            $result = DB::transaction(function () use ($request, $validated, $userId, $warehouseId, $subtype, $idempotencyKey, $requestHash): array {
                $session = PosSession::query()
                    ->where('user_id', $userId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    return ['status' => 'not_found'];
                }

                if ($idempotencyKey) {
                    $existing = CashMovement::query()->where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        return hash_equals((string) $existing->request_hash, $requestHash)
                            ? ['status' => 'replayed', 'movement' => $existing]
                            : ['status' => 'conflict'];
                    }
                }

                $in = (float) $session->cashMovements()->where('type', 'in')->sum('amount');
                $out = (float) $session->cashMovements()->where('type', 'out')->sum('amount');
                $balanceBefore = round((float) $session->opening_amount + $in - $out, 2);
                $amount = round((float) $request->amount, 2);

                if ($validated['type'] === 'out' && $amount > $balanceBefore) {
                    return ['status' => 'insufficient_balance'];
                }

                $movement = CashMovement::create([
                    'pos_session_id' => $session->id,
                    'user_id' => $userId,
                    'type' => $validated['type'],
                    'subtype' => $subtype,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => round($balanceBefore + ($validated['type'] === 'in' ? $amount : -$amount), 2),
                    'reason' => $validated['reason'],
                    'reference_number' => $validated['reference_number'] ?? null,
                    'payment_method' => 'cash',
                    'business_datetime' => $validated['business_datetime'] ?? now(),
                    'notes' => $validated['notes'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $idempotencyKey ? $requestHash : null,
                ]);

                return ['status' => 'created', 'movement' => $movement];
            });
        } catch (QueryException $exception) {
            if (! $idempotencyKey || ! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            $existing = CashMovement::query()->where('idempotency_key', $idempotencyKey)->first();
            $result = $existing && hash_equals((string) $existing->request_hash, $requestHash)
                ? ['status' => 'replayed', 'movement' => $existing]
                : ['status' => 'conflict'];
        }

        if ($result['status'] === 'not_found') {
            return response()->json([
                'message' => 'La caja ya fue cerrada o no existe una sesión abierta para registrar movimientos.',
            ], 409);
        }

        if ($result['status'] === 'conflict') {
            return response()->json([
                'message' => 'La clave de idempotencia ya fue utilizada con datos diferentes.',
            ], 409);
        }

        if ($result['status'] === 'insufficient_balance') {
            return response()->json([
                'message' => 'La caja no tiene saldo suficiente para realizar este retiro.',
                'errors' => ['amount' => ['La caja no tiene saldo suficiente para realizar este retiro.']],
            ], 422);
        }

        return response()->json([
            'message' => $result['status'] === 'replayed'
                ? 'Movimiento de caja recuperado.'
                : 'Movimiento de caja registrado correctamente.',
            'data' => $result['movement'],
            'replayed' => $result['status'] === 'replayed',
        ], $result['status'] === 'replayed' ? 200 : 201);
    }

    /**
     * List cash movements for the current open session.
     */
    public function index(Request $request)
    {
        $userId = $request->input('user_id') ?? auth()->id();
        $warehouseId = $request->integer('warehouse_id');

        if (! $userId) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $session = PosSession::where('user_id', $userId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'open')
            ->first();

        if (! $session) {
            return response()->json(['data' => []], 200);
        }

        $movements = CashMovement::where('pos_session_id', $session->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $movements]);
    }
}
