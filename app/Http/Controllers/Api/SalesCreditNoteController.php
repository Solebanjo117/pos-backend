<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesCreditNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesCreditNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,settled,cancelled'],
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(SalesCreditNote::query()
            ->with(['salesReturn', 'salesOrder:id,order_number', 'customer:id,name,code'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['sales_order_id'] ?? null, fn ($query, $id) => $query->where('sales_order_id', $id))
            ->when($validated['customer_id'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('issued_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('issued_at', '<=', $date))
            ->latest('issued_at')
            ->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function show(SalesCreditNote $salesCreditNote): JsonResponse
    {
        return response()->json(['data' => $salesCreditNote->load([
            'salesReturn.items.product',
            'salesReturn.items.variant',
            'salesOrder:id,order_number,total,status,payment_status',
            'customer:id,name,code',
        ])]);
    }
}
