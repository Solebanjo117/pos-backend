<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Services\SalesReturnOperationalDetailService;
use App\Services\SalesReturnService;
use App\Services\TransactionTraceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnController extends Controller
{
    public function __construct(
        private SalesReturnService $salesReturnService,
        private SalesReturnOperationalDetailService $operationalDetailService,
    ) {}

    public function listAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'sales_order_id' => 'nullable|integer|exists:sales_orders,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'refund_method' => ['nullable', 'string'],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $returns = SalesReturn::with([
            'salesOrder:id,order_number,customer_id,status,total,created_at',
            'customer:id,name,code',
            'warehouse:id,code,name',
            'user:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'items.product',
        ])
            ->when(! empty($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(! empty($validated['customer_id']), fn ($query) => $query->where('customer_id', $validated['customer_id']))
            ->when(! empty($validated['sales_order_id']), fn ($query) => $query->where('sales_order_id', $validated['sales_order_id']))
            ->when(! empty($validated['warehouse_id']), fn ($query) => $query->where('warehouse_id', $validated['warehouse_id']))
            ->when(! empty($validated['refund_method']), fn ($query) => $query->where('refund_method', $validated['refund_method']))
            ->when(! empty($validated['date_from']), fn ($query) => $query->whereDate('created_at', '>=', $validated['date_from']))
            ->when(! empty($validated['date_to']), fn ($query) => $query->whereDate('created_at', '<=', $validated['date_to']))
            ->when(! empty($validated['search']), function ($query) use ($validated): void {
                $search = '%'.$validated['search'].'%';
                $operator = DB::connection()->getDriverName() === 'sqlite' ? 'like' : 'ilike';

                $query->where(function ($query) use ($operator, $search): void {
                    $query->where('return_number', $operator, $search)
                        ->orWhere('reason', $operator, $search)
                        ->orWhereHas('salesOrder', fn ($query) => $query->where('order_number', $operator, $search))
                        ->orWhereHas('customer', fn ($query) => $query->where('name', $operator, $search)->orWhere('code', $operator, $search));
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $returns->getCollection()->transform(function (SalesReturn $return): array {
            $mapped = $return->toArray();
            $processor = $return->status === 'approved'
                ? $return->approver
                : ($return->status === 'rejected' ? $return->rejecter : $return->user);
            $mapped['processed_by_name'] = $processor ? $processor->name : 'Sistema';
            $mapped['processed_at'] = ($return->approved_at ?? $return->rejected_at ?? $return->created_at)?->toISOString();

            return $mapped;
        });

        return response()->json($returns);
    }

    public function candidates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:30',
        ]);

        $operator = DB::connection()->getDriverName() === 'sqlite' ? 'like' : 'ilike';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 10);

        $orders = SalesOrder::query()
            ->with([
                'customer:id,name,code',
                'payments',
                'returns',
                'items.product:id,sku_base,alternate_code,system_id,name',
                'items.variant:id,sku,upc,system_id',
                'items.seller:id,name',
            ])
            ->whereIn('status', ['confirmed', 'completed'])
            ->when($search !== '', function ($query) use ($operator, $search): void {
                $like = '%'.$search.'%';
                $numeric = is_numeric($search) ? (float) $search : null;
                $date = $this->parseCandidateSearchDate($search);

                $query->where(function ($query) use ($operator, $like, $numeric, $date): void {
                    $query->where('order_number', $operator, $like)
                        ->orWhereHas('customer', fn ($query) => $query->where('name', $operator, $like)->orWhere('code', $operator, $like))
                        ->orWhereHas('items.product', fn ($query) => $query
                            ->where('name', $operator, $like)
                            ->orWhere('sku_base', $operator, $like)
                            ->orWhere('alternate_code', $operator, $like)
                            ->orWhere('system_id', $operator, $like))
                        ->orWhereHas('items.variant', fn ($query) => $query
                            ->where('sku', $operator, $like)
                            ->orWhere('upc', $operator, $like)
                            ->orWhere('system_id', $operator, $like))
                        ->when($date !== null, fn ($query) => $query->orWhereDate('date', $date))
                        ->when($numeric !== null, fn ($query) => $query->orWhere('total', $numeric));
                });
            })
            ->latest('date')
            ->limit($perPage)
            ->get()
            ->map(fn (SalesOrder $order): array => $this->returnCandidateRow($order))
            ->filter(fn (array $order): bool => count($order['items']) > 0)
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function operationalDetail(Request $request, SalesReturn $salesReturn, TransactionTraceService $traceService): JsonResponse
    {
        $data = $this->operationalDetailService->get($salesReturn);
        $data['trace'] = $traceService->get('sales_return', $salesReturn->id, $request->user(), 1, 50);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function index(int $orderId): JsonResponse
    {
        $order = SalesOrder::findOrFail($orderId);

        $returns = SalesReturn::where('sales_order_id', $order->id)
            ->with(['items.product', 'items.variant', 'user', 'approver', 'rejecter', 'items.seller'])
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $returns->map(function (SalesReturn $return): array {
            $mapped = $return->toArray();
            $processor = $return->status === 'approved'
                ? $return->approver
                : ($return->status === 'rejected' ? $return->rejecter : $return->user);
            $mapped['processed_by_name'] = $processor ? $processor->name : 'Sistema';
            $mapped['processed_at'] = ($return->approved_at ?? $return->rejected_at ?? $return->created_at)?->toISOString();
            $mapped['items'] = $return->items->map(function ($item): array {
                $mappedItem = $item->toArray();
                $mappedItem['seller_name'] = $item->seller ? $item->seller->name : null;

                return $mappedItem;
            });

            return $mapped;
        });

        return response()->json($data);
    }

    public function store(StoreSalesReturnRequest $request, int $orderId): JsonResponse
    {
        $order = SalesOrder::with('items.product')->findOrFail($orderId);

        try {
            $return = $this->salesReturnService->createForOrder($order, $request->validated(), $request->user());

            return response()->json([
                'message' => 'Devolución creada exitosamente.',
                'data' => $return,
            ], 201);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => 'Error al crear la devolución.',
                'error' => $exception->getMessage().' en la línea '.$exception->getLine().' de '.basename($exception->getFile()),
            ], 500);
        }
    }

    public function approve(int $id): JsonResponse
    {
        $return = SalesReturn::findOrFail($id);

        try {
            $return = $this->salesReturnService->approve($return, request()->user());

            return response()->json([
                'message' => 'Devolución aprobada. Inventario y saldo actualizados.',
                'data' => $return,
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => 'Error al aprobar la devolución.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $return = SalesReturn::findOrFail($id);

        try {
            $return = $this->salesReturnService->reject($return, $request->user());
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        return response()->json([
            'message' => 'Devolución rechazada.',
            'data' => $return,
        ]);
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        $errors = collect($exception->errors())->flatten()->values()->all();

        return response()->json([
            'message' => $errors[0] ?? 'No se pudo procesar la devolución.',
            'errors' => $errors,
        ], 422);
    }

    private function returnCandidateRow(SalesOrder $order): array
    {
        $paid = (float) $order->payments
            ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
            ->sum('amount');
        $totalReturned = (float) $order->returns
            ->where('status', 'approved')
            ->sum('total_amount');
        $effectiveTotal = max(0, (float) $order->total - $totalReturned);
        $amountDue = round(max(0, $effectiveTotal - $paid), 2);
        $returnableAmount = 0.0;
        $items = $order->items
            ->filter(fn ($item): bool => ! (bool) $item->is_custom_line)
            ->map(function ($item) use (&$returnableAmount): array {
                $alreadyReserved = (int) SalesReturnItem::query()
                    ->where('sales_order_item_id', $item->id)
                    ->whereHas('salesReturn', function ($query): void {
                        $query->whereIn('status', ['pending', 'approved']);
                    })
                    ->sum('quantity');
                $returnable = max(0, (int) $item->quantity - $alreadyReserved);
                $lineTotal = $item->quantity > 0
                    ? round(((float) $item->total / (int) $item->quantity) * $returnable, 2)
                    : 0;
                $returnableAmount += $lineTotal;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->variant?->sku ?? $item->product_sku,
                    'quantity' => $returnable,
                    'sold_quantity' => (int) $item->quantity,
                    'already_returned_quantity' => $alreadyReserved,
                    'unit_price' => (float) $item->unit_price,
                    'discount_percent' => (float) $item->discount_percent,
                    'tax_percent' => (int) ($item->tax_percent ?? 0),
                    'total' => $lineTotal,
                    'seller_name' => $item->seller?->name,
                ];
            })
            ->filter(fn (array $item): bool => $item['quantity'] > 0)
            ->values()
            ->all();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'date' => $order->date?->toDateString(),
            'total' => (float) $order->total,
            'total_paid' => round($paid, 2),
            'total_returned' => round($totalReturned, 2),
            'amount_due' => $amountDue,
            'returnable_amount' => round($returnableAmount, 2),
            'recommended_refund_method' => $amountDue > 0 ? SalesReturnService::METHOD_RECEIVABLE : 'refund',
            'has_customer' => $order->customer_id !== null,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'code' => $order->customer->code,
            ] : null,
            'items' => $items,
        ];
    }

    private function parseCandidateSearchDate(string $search): ?string
    {
        $search = trim($search);

        if (! preg_match('/^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})$/', $search)) {
            return null;
        }

        try {
            return Carbon::parse($search)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
