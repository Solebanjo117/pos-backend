<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\PosSession;
use App\Models\SalesOrderPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosSessionController extends Controller
{
    /**
     * Get the current open session for the authenticated user
     */
    public function current(Request $request)
    {
        $userId      = $request->input('user_id') ?? auth()->id();
        $warehouseId = $request->input('warehouse_id'); // optional: scope to warehouse

        if (!$userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la petición.'], 401);
        }

        $query = PosSession::where('user_id', $userId)->where('status', 'open');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $session = $query->latest()->first();

        $summary = null;

        if ($session) {
            // Sum payments by method from the payments created during this session
            $paymentsQuery = \App\Models\SalesOrderPayment::where('created_by', $session->user_id)
                ->where('paid_at', '>=', $session->opened_at);
            
            if ($session->closed_at) {
                $paymentsQuery->where('paid_at', '<=', $session->closed_at);
            }

            $cashSales       = (float) (clone $paymentsQuery)->where('method', 'cash')->sum('amount');
            $electronicSales = (float) (clone $paymentsQuery)->where('method', '!=', 'cash')->sum('amount');

            // Breakdown by specific electronic methods for detailed reporting
            $allElectronic = (clone $paymentsQuery)
                ->where('method', '!=', 'cash')
                ->selectRaw('method, SUM(amount) as total')
                ->groupBy('method')
                ->pluck('total', 'method')
                ->toArray();

            // Cash movements (retiros / ingresos extra)
            $movementsIn  = (float) CashMovement::where('pos_session_id', $session->id)->where('type', 'in')->sum('amount');
            $movementsOut = (float) CashMovement::where('pos_session_id', $session->id)->where('type', 'out')->sum('amount');

            $expectedCash = (float) $session->opening_amount + $cashSales + $movementsIn - $movementsOut;

            // Discounts
            $totalDiscounts = (float) \App\Models\SalesOrder::where('pos_session_id', $session->id)
                ->whereIn('status', ['completed', 'confirmed', 'processing'])
                ->sum('discount_amount');

            // Update expected_amount (cash only)
            $session->expected_amount = $expectedCash;
            $session->save();

            $summary = [
                'opening_amount'       => (float) $session->opening_amount,
                'cash_sales'           => $cashSales,
                'electronic_sales'     => $electronicSales,
                'total_discounts'      => $totalDiscounts,
                'expected_cash'        => $expectedCash,
                'total_billed'         => $cashSales + $electronicSales,
                'cash_movements_in'    => $movementsIn,
                'cash_movements_out'   => $movementsOut,
                'electronic_breakdown' => $allElectronic,
            ];
        }

        return response()->json([
            'data'    => $session,
            'summary' => $summary,
        ]);
    }

    /**
     * Open a new session
     */
    public function open(Request $request)
    {
        $userId = $request->input('user_id') ?? auth()->id();

        if (!$userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la petición.'], 401);
        }

        $request->validate([
            'opening_amount' => 'required|numeric|min:0',
            'warehouse_id'   => 'nullable|integer|exists:warehouses,id',
        ]);

        $warehouseId = $request->input('warehouse_id');

        // Check if there's already an open session for this user (and warehouse if provided)
        $existingQuery = PosSession::where('user_id', $userId)->where('status', 'open');
        if ($warehouseId) {
            $existingQuery->where('warehouse_id', $warehouseId);
        }
        $existing = $existingQuery->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya existe una sesión abierta para este usuario' . ($warehouseId ? ' en esta sucursal.' : '.'),
                'data'    => $existing
            ], 400);
        }

        $session = PosSession::create([
            'user_id'         => $userId,
            'warehouse_id'    => $warehouseId,
            'opened_at'       => now(),
            'opening_amount'  => $request->opening_amount,
            'expected_amount' => $request->opening_amount, // Initial expected
            'status'          => 'open'
        ]);

        return response()->json([
            'message' => 'Sesión de caja abierta correctamente',
            'data'    => $session
        ], 201);
    }

    /**
     * Close the current session
     */
    public function close(Request $request)
    {
        $userId = $request->input('user_id') ?? auth()->id();
        
        if (!$userId) {
            return response()->json(['message' => 'No autorizado. Se requiere user_id en la petición.'], 401);
        }

        $request->validate([
            'closing_amount' => 'required|numeric|min:0',
            'closing_details' => 'nullable|array',
            'notes' => 'nullable|string'
        ]);

        $session = PosSession::where('user_id', $userId)
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'No hay una sesión abierta activa.'
            ], 404);
        }

        // ── Calculate breakdown by payment method using actual payments in session's timeframe ──
        $paymentsQuery = \App\Models\SalesOrderPayment::where('created_by', $session->user_id)
            ->where('paid_at', '>=', $session->opened_at);
            
        // Use now() as upper bound since we are closing it exactly now
        $closeTime = now();
        $paymentsQuery->where('paid_at', '<=', $closeTime);

        $cashSales       = (float) (clone $paymentsQuery)->where('method', 'cash')->sum('amount');
        $electronicSales = (float) (clone $paymentsQuery)->where('method', '!=', 'cash')->sum('amount');
        $totalSales      = $cashSales + $electronicSales;
        
        // Note: Shipping revenue might still be order-based. We'll stick to orderIds for shipping.
        $ordersQuery = \App\Models\SalesOrder::where('pos_session_id', $session->id)
            ->whereIn('status', ['completed', 'confirmed', 'processing']);
            
        $shippingRevenue = (float) $ordersQuery->sum('shipping_cost');
        $totalDiscounts  = (float) $ordersQuery->sum('discount_amount');

        // Detailed breakdown by specific method
        $allElectronic = (clone $paymentsQuery)
            ->where('method', '!=', 'cash')
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        // Cash movements (retiros / ingresos extra)
        $movementsIn  = (float) CashMovement::where('pos_session_id', $session->id)->where('type', 'in')->sum('amount');
        $movementsOut = (float) CashMovement::where('pos_session_id', $session->id)->where('type', 'out')->sum('amount');

        $expected = $session->opening_amount + $cashSales + $movementsIn - $movementsOut;
        $closingAmount = (float) $request->closing_amount;
        $difference = $closingAmount - $expected;

        // Build detailed closing summary
        $closingDetails = $request->input('closing_details', []);
        $closingDetails['summary'] = [
            'opening_amount'       => (float) $session->opening_amount,
            'cash_sales'           => (float) $cashSales,
            'electronic_sales'     => (float) $electronicSales,
            'total_sales'          => (float) $totalSales,
            'total_discounts'      => (float) $totalDiscounts,
            'shipping_revenue'     => (float) $shippingRevenue,
            'cash_movements_in'    => $movementsIn,
            'cash_movements_out'   => $movementsOut,
            'expected_cash'        => (float) $expected,
            'declared_cash'        => $closingAmount,
            'difference'           => $difference,
            'electronic_breakdown' => $allElectronic,
        ];

        $session->update([
            'closed_at'       => now(),
            'closing_amount'  => $closingAmount,
            'expected_amount' => $expected,
            'closing_details' => $closingDetails,
            'status'          => 'closed',
            'notes'           => $request->notes
        ]);

        return response()->json([
            'message' => 'Sesión de caja cerrada correctamente',
            'data' => $session
        ]);
    }

    /**
     * List session history (for reporting / admin)
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
            'data' => $query->paginate($request->input('per_page', 20))
        ]);
    }

    /**
     * Show a single session with its associated sales orders.
     */
    public function show(PosSession $posSession)
    {
        $posSession->load(['user:id,name', 'warehouse:id,name', 'cashMovements']);

        // Fetch related sales orders
        $salesOrders = \App\Models\SalesOrder::where('pos_session_id', $posSession->id)
            ->select('id', 'order_number', 'total', 'subtotal', 'tax_amount', 'discount_amount', 'status', 'payment_method', 'customer_id', 'created_at')
            ->with('customer:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        $closingDetails = $posSession->closing_details ?? [];

        // ── Calculate breakdown by payment method using actual payments in session's timeframe ──
        $paymentsQuery = SalesOrderPayment::where('created_by', $posSession->user_id)
            ->where('paid_at', '>=', $posSession->opened_at);
            
        if ($posSession->closed_at) {
            $paymentsQuery->where('paid_at', '<=', $posSession->closed_at);
        }

        $cashSales       = (float) (clone $paymentsQuery)->where('method', 'cash')->sum('amount');
        $electronicSales = (float) (clone $paymentsQuery)->where('method', '!=', 'cash')->sum('amount');
        $totalSales      = $cashSales + $electronicSales;

        // Disounts and Orders
        $ordersQuery = \App\Models\SalesOrder::where('pos_session_id', $posSession->id)
            ->whereIn('status', ['completed', 'confirmed', 'processing']);
            
        $totalDiscounts = (float) $ordersQuery->sum('discount_amount');
        $shippingRevenue = (float) $ordersQuery->sum('shipping_cost');

        // Detailed electronic breakdown by specific method
        $allElectronic = (clone $paymentsQuery)
            ->where('method', '!=', 'cash')
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        $movementsIn  = (float) CashMovement::where('pos_session_id', $posSession->id)->where('type', 'in')->sum('amount');
        $movementsOut = (float) CashMovement::where('pos_session_id', $posSession->id)->where('type', 'out')->sum('amount');
        $openingAmount = (float) $posSession->opening_amount;
        $expectedCash  = $openingAmount + $cashSales + $movementsIn - $movementsOut;
        $declaredCash  = (float) ($posSession->closing_amount ?? $expectedCash);
        $difference    = $declaredCash - $expectedCash;

        // ── Rebuild summary if missing (sessions closed before the format existed) ──
        if ($posSession->status === 'closed' && empty($closingDetails['summary'])) {
            $closingDetails['summary'] = [
                'opening_amount'       => $openingAmount,
                'cash_sales'           => $cashSales,
                'electronic_sales'     => $electronicSales,
                'total_sales'          => $totalSales,
                'total_discounts'      => $totalDiscounts,
                'shipping_revenue'     => $shippingRevenue,
                'cash_movements_in'    => $movementsIn,
                'cash_movements_out'   => $movementsOut,
                'expected_cash'        => $expectedCash,
                'declared_cash'        => $declaredCash,
                'difference'           => $difference,
                'electronic_breakdown' => $allElectronic,
            ];
        }

        // ── Rebuild electronic_payments if missing ──
        if ($posSession->status === 'closed' && empty($closingDetails['electronic_payments'])) {
            $closingDetails['electronic_payments'] = $allElectronic;
        }

        $posSession->closing_details = $closingDetails;

        return response()->json([
            'data'           => $posSession,
            'sales_orders'   => $salesOrders,
            'cash_movements' => $posSession->cashMovements,
        ]);
    }
}
