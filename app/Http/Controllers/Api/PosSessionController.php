<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClosePosSessionRequest;
use App\Http\Requests\CurrentPosSessionRequest;
use App\Http\Requests\OpenPosSessionRequest;
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
     * Get the current open session for the authenticated user in the effective warehouse.
     */
    public function current(CurrentPosSessionRequest $request)
    {
        $userId = Auth::id();
        $warehouseId = $request->integer('warehouse_id');

        $session = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->where('warehouse_id', $warehouseId)
            ->latest()
            ->first();

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
     * Open a new session for the authenticated user in the effective warehouse.
     */
    public function open(OpenPosSessionRequest $request)
    {
        $userId = Auth::id();
        $warehouseId = $request->integer('warehouse_id');

        $existing = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya existe una sesion abierta para este usuario en esta sucursal.',
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
     * Close the open session for the authenticated user in the effective warehouse.
     */
    public function close(ClosePosSessionRequest $request)
    {
        $userId = Auth::id();
        $warehouseId = $request->integer('warehouse_id');

        $session = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'No hay una sesion abierta activa para esta sucursal.',
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
        $session->closing_details = $closingDetails;

        $sessionData = $session->toArray();
        $sessionData['closing_details'] = $closingDetails;

        return response()->json([
            'message' => 'Sesion de caja cerrada correctamente',
            'data' => $sessionData,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
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

        $purchasePayments = \App\Models\PurchaseOrderPayment::query()
            ->where('pos_session_id', $posSession->id)
            ->with(['purchaseOrder:id,po_number,supplier_id,total', 'purchaseOrder.supplier:id,name', 'user:id,name'])
            ->orderBy('paid_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (\App\Models\PurchaseOrderPayment $payment): array => [
                'id' => $payment->id,
                'purchase_order_id' => $payment->purchase_order_id,
                'pos_session_id' => $payment->pos_session_id,
                'cash_movement_id' => $payment->cash_movement_id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference_number' => $payment->reference_number,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
                'purchase_order' => $payment->purchaseOrder ? [
                    'id' => $payment->purchaseOrder->id,
                    'po_number' => $payment->purchaseOrder->po_number,
                    'total' => $payment->purchaseOrder->total,
                    'supplier' => $payment->purchaseOrder->supplier ? [
                        'id' => $payment->purchaseOrder->supplier->id,
                        'name' => $payment->purchaseOrder->supplier->name,
                    ] : null,
                ] : null,
                'user' => $payment->user ? [
                    'id' => $payment->user->id,
                    'name' => $payment->user->name,
                ] : null,
            ]);

        $accountPayments = \App\Models\AccountPaymentBatch::query()
            ->where('pos_session_id', $posSession->id)
            ->with(['applications', 'creator:id,name'])
            ->orderBy('paid_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (\App\Models\AccountPaymentBatch $batch): array => [
                'id' => $batch->id,
                'account_type' => $batch->account_type,
                'account_id' => $batch->account_id,
                'direction' => $batch->direction,
                'batch_number' => $batch->batch_number,
                'amount' => $batch->amount,
                'method' => $batch->method,
                'reference_number' => $batch->reference_number,
                'notes' => $batch->notes,
                'paid_at' => $batch->paid_at,
                'created_at' => $batch->created_at,
                'creator' => $batch->creator ? [
                    'id' => $batch->creator->id,
                    'name' => $batch->creator->name,
                ] : null,
                'applications' => $batch->applications->map(fn ($application): array => [
                    'document_type' => $application->document_type,
                    'document_id' => $application->document_id,
                    'document_number' => $application->document_number,
                    'amount' => $application->amount,
                ])->values(),
            ]);

        return response()->json([
            'data' => $posSession,
            'sales_orders' => $salesOrders,
            'cash_movements' => $posSession->cashMovements,
            'purchase_payments' => $purchasePayments,
            'account_payments' => $accountPayments,
        ]);
    }
}
