<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClosePosSessionRequest;
use App\Http\Requests\CurrentPosSessionRequest;
use App\Http\Requests\IndexPosSessionRequest;
use App\Http\Requests\OpenPosSessionRequest;
use App\Models\PosSession;
use App\Services\PosSessionOperationalDetailService;
use App\Services\PosSessionReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PosSessionController extends Controller
{
    public function __construct(
        private PosSessionReconciliationService $reconciliationService,
        private PosSessionOperationalDetailService $operationalDetailService
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

        try {
            $result = DB::transaction(function () use ($request, $userId, $warehouseId): array {
                $existing = PosSession::query()
                    ->where('user_id', $userId)
                    ->where('status', 'open')
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['created' => false, 'session' => $existing];
                }

                return [
                    'created' => true,
                    'session' => PosSession::create([
                        'user_id' => $userId,
                        'warehouse_id' => $warehouseId,
                        'opened_at' => now(),
                        'opening_amount' => $request->opening_amount,
                        'expected_amount' => $request->opening_amount,
                        'status' => 'open',
                    ]),
                ];
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = PosSession::query()
                ->where('user_id', $userId)
                ->where('status', 'open')
                ->where('warehouse_id', $warehouseId)
                ->first();

            return response()->json([
                'message' => 'La sesion ya fue abierta por otra solicitud.',
                'data' => $existing,
            ], 409);
        }

        if (! $result['created']) {
            return response()->json([
                'message' => 'Ya existe una sesion abierta para este usuario en esta sucursal.',
                'data' => $result['session'],
            ], 400);
        }

        return response()->json([
            'message' => 'Sesion de caja abierta correctamente',
            'data' => $result['session'],
        ], 201);
    }

    /**
     * Close the open session for the authenticated user in the effective warehouse.
     */
    public function close(ClosePosSessionRequest $request)
    {
        $userId = Auth::id();
        $warehouseId = $request->integer('warehouse_id');

        $result = DB::transaction(function () use ($request, $userId, $warehouseId): array {
            $session = PosSession::query()
                ->where('user_id', $userId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return ['status' => 'not_found'];
            }

            $closeTime = now();
            $closingAmount = (float) $request->closing_amount;
            $closingDetails = $request->input('closing_details') ?? [];
            $reconciliation = $this->reconciliationService->build($session, $closeTime, $closingDetails, $closingAmount);
            $summary = $reconciliation['summary'];

            if (abs((float) $summary['total_difference']) > 0.005 && ! trim((string) $request->input('notes', ''))) {
                return ['status' => 'validation_error'];
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

            return ['status' => 'closed', 'data' => $sessionData];
        });

        if ($result['status'] === 'not_found') {
            return response()->json([
                'message' => 'No hay una sesion abierta activa para esta sucursal.',
            ], 404);
        }

        if ($result['status'] === 'validation_error') {
            return response()->json([
                'message' => 'Debe agregar una justificacion cuando el cierre tiene diferencias.',
                'errors' => [
                    'notes' => ['Debe agregar una justificacion cuando el cierre tiene diferencias.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Sesion de caja cerrada correctamente',
            'data' => $result['data'],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * List session history for reporting.
     */
    public function index(IndexPosSessionRequest $request)
    {
        $query = PosSession::with(['user:id,name', 'warehouse:id,name'])
            ->orderByRaw('COALESCE(closed_at, opened_at) DESC')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $dateColumn = match ($request->input('status')) {
                'open' => 'opened_at',
                'closed' => 'closed_at',
                default => null,
            };

            $query->where(function ($query) use ($request, $dateColumn): void {
                if ($dateColumn) {
                    $this->applySessionDateRange($query, $dateColumn, $request);

                    return;
                }

                $query->where(function (Builder $query) use ($request): void {
                    $query->where('status', 'open');
                    $this->applySessionDateRange($query, 'opened_at', $request);
                })->orWhere(function (Builder $query) use ($request): void {
                    $query->where('status', 'closed');
                    $this->applySessionDateRange($query, 'closed_at', $request);
                });
            });
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
        $posSession->load(['user:id,name', 'warehouse:id,name', 'cashMovements.user:id,name']);

        $salesOrders = \App\Models\SalesOrder::where('pos_session_id', $posSession->id)
            ->select('id', 'order_number', 'user_id', 'total', 'subtotal', 'tax_amount', 'discount_amount', 'status', 'payment_method', 'customer_id', 'created_at')
            ->with(['customer:id,name', 'user:id,name'])
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
                ])->values()->all(),
            ]);

        $operationalExpenses = \App\Models\OperationalExpense::query()
            ->where('pos_session_id', $posSession->id)
            ->with(['warehouse:id,name', 'creator:id,name', 'cashMovement:id,pos_session_id,amount'])
            ->orderBy('expense_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $operationalDetail = $this->operationalDetailService->build(
            $posSession,
            $salesOrders,
            $purchasePayments,
            $accountPayments,
            $operationalExpenses
        );

        return response()->json([
            'data' => $posSession,
            'summary' => $closingDetails['summary'],
            'reconciliation_rows' => $closingDetails['reconciliation_rows'],
            'documents' => $operationalDetail['documents'],
            'timeline' => $operationalDetail['timeline'],
            'sales_orders' => $salesOrders,
            'cash_movements' => $posSession->cashMovements,
            'purchase_payments' => $purchasePayments,
            'account_payments' => $accountPayments,
            'operational_expenses' => $operationalExpenses,
        ]);
    }

    private function applySessionDateRange(Builder $query, string $column, IndexPosSessionRequest $request): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate($column, '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate($column, '<=', $request->date_to);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains($exception->getMessage(), 'pos_sessions_one_open_per_user_warehouse');
    }
}
