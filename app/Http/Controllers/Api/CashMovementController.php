<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashMovementRequest;
use App\Models\CashMovement;
use App\Models\PosSession;
use App\Services\CashMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashMovementController extends Controller
{
    public function store(StoreCashMovementRequest $request, CashMovementService $service): JsonResponse
    {
        $validated = $request->validated();
        $validated['subtype'] ??= $validated['type'] === 'in' ? 'cash_in' : 'cash_out';

        try {
            $movement = $service->record($request->user(), (int) $validated['warehouse_id'], $validated);
        } catch (\InvalidArgumentException $exception) {
            $status = str_contains($exception->getMessage(), 'saldo suficiente') ? 422 : 409;

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $status === 422 ? ['amount' => [$exception->getMessage()]] : null,
            ], $status);
        }

        $replayed = ! $movement->wasRecentlyCreated;

        return response()->json([
            'message' => $replayed ? 'Movimiento de caja recuperado.' : 'Movimiento de caja registrado correctamente.',
            'data' => $movement,
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->input('user_id') ?? auth()->id();
        $warehouseId = $request->integer('warehouse_id');
        if (! $userId) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $session = PosSession::query()
            ->where('user_id', $userId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'open')
            ->first();
        if (! $session) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => CashMovement::query()
                ->where('pos_session_id', $session->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
