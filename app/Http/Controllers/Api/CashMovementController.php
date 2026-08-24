<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\PosSession;
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

        $request->validate([
            'type' => 'required|in:in,out',
            'subtype' => 'nullable|in:cash_in,cash_out,deposit,withdrawal,adjustment_positive,adjustment_negative',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:120',
            'business_datetime' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
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

        $movement = DB::transaction(function () use ($request, $userId, $warehouseId, $subtype): ?CashMovement {
            $session = PosSession::query()
                ->where('user_id', $userId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return null;
            }

            $in = (float) $session->cashMovements()->where('type', 'in')->sum('amount');
            $out = (float) $session->cashMovements()->where('type', 'out')->sum('amount');
            $balanceBefore = round((float) $session->opening_amount + $in - $out, 2);
            $amount = round((float) $request->amount, 2);

            return CashMovement::create([
                'pos_session_id' => $session->id,
                'user_id' => $userId,
                'type' => $request->type,
                'subtype' => $subtype,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => round($balanceBefore + ($request->type === 'in' ? $amount : -$amount), 2),
                'reason' => $request->reason,
                'reference_number' => $request->reference_number,
                'payment_method' => 'cash',
                'business_datetime' => $request->business_datetime ?? now(),
                'notes' => $request->notes,
            ]);
        });

        if (! $movement) {
            return response()->json([
                'message' => 'No hay una sesión de caja abierta para registrar movimientos.',
            ], 404);
        }

        return response()->json([
            'message' => 'Movimiento de caja registrado correctamente.',
            'data' => $movement,
        ], 201);
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
