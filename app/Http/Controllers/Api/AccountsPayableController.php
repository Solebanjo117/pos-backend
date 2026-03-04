<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class AccountsPayableController extends Controller
{
    /**
     * Return a summary of Accounts Payable: all suppliers with their
     * outstanding debt on purchase orders.
     */
    public function index(): JsonResponse
    {
        $suppliers = Supplier::whereHas('purchaseOrders')
            ->get()
            ->map(function (Supplier $supplier) {
                $orders = $supplier->purchaseOrders()
                    ->whereIn('payment_status', ['pending', 'partial'])
                    ->with('payments')
                    ->get();

                $totalOrdersAmount = $orders->sum('total');
                $totalPaid         = $orders->sum(fn($o) => $o->payments->sum('amount'));
                $totalDebt         = max(0, $totalOrdersAmount - $totalPaid);

                return [
                    'id'                   => $supplier->id,
                    'name'                 => $supplier->name,
                    'email'                => $supplier->email,
                    'phone'                => $supplier->phone,
                    'pending_orders_count' => $orders->count(),
                    'total_orders_amount'  => round($totalOrdersAmount, 2),
                    'total_paid'           => round($totalPaid, 2),
                    'total_debt'           => round($totalDebt, 2),
                ];
            })
            ->filter(fn($s) => $s['pending_orders_count'] > 0)
            ->sortByDesc('total_debt')
            ->values();

        return response()->json([
            'data' => $suppliers,
        ]);
    }
}
