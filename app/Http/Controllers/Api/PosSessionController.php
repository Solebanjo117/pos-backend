<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClosePosSessionRequest;
use App\Models\PosSession;
use App\Services\PosSessionReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosSessionController extends Controller
{
    public function __construct(
        private PosSessionReconciliationService $reconciliationService
    ) {}

    /**
     * Get the current open session for the authenticated user.
     */
    public function current(Request $request)
    {
        $userId = $request->input('user_id') ?? Auth::id();
        $warehouseId = $request->input('warehouse_id');

        if (! $userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la peticion.'], 401);
        }

        $query = PosSession::where('user_id', $userId)->where('status', 'open');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $session = $query->latest()->first();
        $summary = null;

        if ($session) {
            $reconciliation = $this->reconciliationService->build($session);
            $summary = $reconciliation['summary'];

            $session->expected_amount = $summary['expected_cash'];
            $session->save();
        }

        return response()->json([
            'data' => $session,
            'summary' => $summary,
        ]);
    }

    /**
     * Open a new session.
     */
    public function open(Request $request)
    {
        $userId = $request->input('user_id') ?? Auth::id();

        if (! $userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la peticion.'], 401);
        }

        $request->validate([
            'opening_amount' => 'required|numeric|min:0',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $warehouseId = $request->input('warehouse_id');

        $existingQuery = PosSession::where('user_id', $userId)->where('status', 'open');
        if ($warehouseId) {
            $existingQuery->where('warehouse_id', $warehouseId);
        }

        $existing = $existingQuery->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya existe una sesion abierta para este usuario'.($warehouseId ? ' en esta sucursal.' : '.'),
                'data' => $existing,
            ], 400);
        }

        $session = PosSession::create([
            'user_id' => $userId,
            'warehouse_id' => $warehouseId,
            'opened_at' => now(),
            'opening_amount' => $request->opening_amount,
            'expected_amount' => $request->opening_amount,
            'status' => 'open',
        ]);

        return response()->json([
            'message' => 'Sesion de caja abierta correctamente',
            'data' => $session,
        ], 201);
    }

    /**
     * Close the current session.
     */
    public function close(ClosePosSessionRequest $request)
    {
        $userId = $request->input('user_id') ?? Auth::id();

        if (! $userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la peticion.'], 401);
        }

        $session = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'No hay una sesion abierta activa.',
            ], 404);
        }

        $closeTime = now();
        $closingAmount = (float) $request->closing_amount;
        $closingDetails = $request->input('closing_details') ?? [];
        $reconciliation = $this->reconciliationService->build($session, $closeTime, $closingDetails, $closingAmount);
        $summary = $reconciliation['summary'];

        if (abs((float) $summary['total_difference']) > 0.005 && ! trim((string) $request->input('notes', ''))) {
            return response()->json([
                'message' => 'Debe agregar una justificacion cuando el cierre tiene diferencias.',
                'errors' => [
                    'notes' => ['Debe agregar una justificacion cuando el cierre tiene diferencias.'],
                ],
            ], 422);
        }

        $closingDetails['reconciliation_rows'] = $reconciliation['rows'];
        $closingDetails['summary'] = $summary;
        $closingDetails['electronic_payments'] = $summary['electronic_breakdown'];

        $session->update([
            'closed_at' => $closeTime,
            'closing_amount' => $closingAmount,
            'expected_amount' => $summary['expected_cash'],
            'closing_details' => $closingDetails,
            'status' => 'closed',
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Sesion de caja cerrada correctamente',
            'data' => $session,
        ]);
    }

    /**
     * List session history for reporting.
     */
    public function index(Request $request)
    {
        $query = PosSession::with(['user:id,name', 'warehouse:id,name'])
            ->orderBy('closed_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('closed_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('closed_at', '<=', $request->date_to);
        }

        return response()->json([
            'data' => $query->paginate($request->input('per_page', 20)),
        ]);
    }

    /**
     * Show a single session with its associated sales orders.
     */
    public function show(PosSession $posSession)
    {
        $posSession->load(['user:id,name', 'warehouse:id,name', 'cashMovements']);

        $salesOrders = \App\Models\SalesOrder::where('pos_session_id', $posSession->id)
            ->select('id', 'order_number', 'total', 'subtotal', 'tax_amount', 'discount_amount', 'status', 'payment_method', 'customer_id', 'created_at')
            ->with('customer:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        $closingDetails = $posSession->closing_details ?? [];
        $reconciliation = $this->reconciliationService->build(
            $posSession,
            $posSession->closed_at,
            $closingDetails,
            $posSession->closing_amount !== null ? (float) $posSession->closing_amount : null
        );

        if (! is_array($closingDetails)) {
            $closingDetails = [];
        }

        $closingDetails['reconciliation_rows'] = $reconciliation['rows'];
        $closingDetails['summary'] = array_merge(
            is_array($closingDetails['summary'] ?? null) ? $closingDetails['summary'] : [],
            $reconciliation['summary']
        );

        $closingDetails['summary']['reconciliation_rows'] = $closingDetails['reconciliation_rows'];
        $closingDetails['electronic_payments'] = $closingDetails['summary']['electronic_breakdown'] ?? [];

        $posSession->closing_details = $closingDetails;

        return response()->json([
            'data' => $posSession,
            'sales_orders' => $salesOrders,
            'cash_movements' => $posSession->cashMovements,
        ]);
    }
}
