<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\PosSession;
use Illuminate\Http\Request;

class CashMovementController extends Controller
{
    /**
     * Store a new cash movement for the current open session.
     */
    public function store(Request $request)
    {
        $userId = $request->input('user_id') ?? auth()->id();

        if (!$userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la petición.'], 401);
        }

        $request->validate([
            'type'   => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'notes'  => 'nullable|string|max:1000',
        ]);

        // Find the open session for this user
        $session = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'No hay una sesión de caja abierta para registrar movimientos.',
            ], 404);
        }

        $movement = CashMovement::create([
            'pos_session_id' => $session->id,
            'user_id'        => $userId,
            'type'           => $request->type,
            'amount'         => $request->amount,
            'reason'         => $request->reason,
            'notes'          => $request->notes,
        ]);

        return response()->json([
            'message' => 'Movimiento de caja registrado correctamente.',
            'data'    => $movement,
        ], 201);
    }

    /**
     * List cash movements for the current open session.
     */
    public function index(Request $request)
    {
        $userId = $request->input('user_id') ?? auth()->id();

        if (!$userId) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $session = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json(['data' => []], 200);
        }

        $movements = CashMovement::where('pos_session_id', $session->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $movements]);
    }
}
