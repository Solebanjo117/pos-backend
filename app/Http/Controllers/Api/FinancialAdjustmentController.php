<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReverseFinancialAdjustmentRequest;
use App\Http\Requests\StoreFinancialAdjustmentRequest;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialAdjustment;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\FinancialAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FinancialAdjustmentController extends Controller
{
    public function __construct(private FinancialAdjustmentService $service) {}

    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageFinancialAdjustments(), 403);

        return response()->json(['data' => [
            'types' => collect(FinancialAdjustment::TYPES)->map(fn (string $label, string $value): array => compact('value', 'label'))->values(),
            'directions' => [
                ['value' => FinancialAdjustment::DIRECTION_INCREASE, 'label' => 'Aumentar saldo'],
                ['value' => FinancialAdjustment::DIRECTION_DECREASE, 'label' => 'Disminuir saldo'],
            ],
            'pos_sessions' => PosSession::query()->with(['warehouse:id,name', 'user:id,name'])->where('status', 'open')->get()->map(fn (PosSession $session): array => [
                'value' => $session->id,
                'label' => 'Caja #'.$session->id.' · '.($session->warehouse?->name ?? 'Sin almacén').' · '.($session->user?->name ?? 'Sin usuario'),
            ]),
            'financial_accounts' => FinancialAccount::query()->with('ledgerAccount:id,code,name')->where('is_active', true)->where('is_legacy', false)->get()->map(fn (FinancialAccount $account): array => [
                'value' => $account->id,
                'label' => $account->name,
                'type' => $account->type,
                'institution_name' => $account->institution_name,
                'masked_number' => $account->masked_number,
                'balance' => (float) $account->current_balance,
            ]),
            'ledger_accounts' => LedgerAccount::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type'])->map(fn (LedgerAccount $account): array => [
                'value' => $account->id,
                'label' => $account->code.' · '.$account->name,
                'type' => $account->type,
            ]),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name'])->map(fn (Warehouse $warehouse): array => ['value' => $warehouse->id, 'label' => $warehouse->name]),
            'customers' => Customer::query()->where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (Customer $customer): array => ['value' => $customer->id, 'label' => $customer->name]),
            'suppliers' => Supplier::query()->where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (Supplier $supplier): array => ['value' => $supplier->id, 'label' => $supplier->name]),
            'sales_orders' => SalesOrder::query()->whereNotNull('customer_id')->whereNotIn('status', ['cancelled'])->latest('id')->limit(500)->get(['id', 'customer_id', 'order_number', 'total'])->map(fn (SalesOrder $order): array => [
                'value' => $order->id, 'parent_id' => $order->customer_id, 'label' => $order->order_number, 'total' => (float) $order->total, 'balance' => (float) $order->amount_due,
            ]),
            'purchase_orders' => PurchaseOrder::query()->whereNotNull('supplier_id')->whereNotIn('status', ['cancelled'])->latest('id')->limit(500)->get(['id', 'supplier_id', 'po_number', 'supplier_invoice_number', 'total'])->map(fn (PurchaseOrder $order): array => [
                'value' => $order->id, 'parent_id' => $order->supplier_id, 'label' => $order->supplier_invoice_number ?: $order->po_number, 'total' => (float) $order->total, 'balance' => (float) $order->amount_due,
            ]),
            'products' => Product::query()->with('variants:id,product_id,sku')->where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (Product $product): array => [
                'value' => $product->id, 'label' => $product->name, 'variants' => $product->variants->map(fn ($variant): array => ['value' => $variant->id, 'label' => $variant->sku ?: 'Sin código']),
            ]),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageFinancialAdjustments(), 403);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(FinancialAdjustment::TYPES))],
            'status' => ['nullable', 'in:completed,reversed'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = FinancialAdjustment::query()
            ->with(['user:id,name', 'affectedLedgerAccount:id,code,name', 'counterpartyLedgerAccount:id,code,name', 'financialAccount:id,name,institution_name,masked_number', 'reversalOf:id,folio,type', 'reversedBy:id,folio,type'])
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('adjustment_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('adjustment_date', '<=', $date))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($nested) use ($search): void {
                $nested->where('folio', 'like', '%'.$search.'%')->orWhere('reason', 'like', '%'.$search.'%')->orWhere('reference', 'like', '%'.$search.'%');
            }))
            ->latest('business_datetime')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
        $paginator->getCollection()->transform(
            fn (FinancialAdjustment $adjustment): array => $this->present($adjustment, $request),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreFinancialAdjustmentRequest $request): JsonResponse
    {
        try {
            $result = $this->service->create($request->validated(), $request->user(), $request->ip());
        } catch (InvalidArgumentException $exception) {
            $status = str_contains($exception->getMessage(), 'idempotencia') ? 409 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json(['data' => $this->present($result['adjustment'], $request), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, FinancialAdjustment $financialAdjustment): JsonResponse
    {
        abort_unless($request->user()?->canManageFinancialAdjustments(), 403);
        $result = $this->service->createDetail($financialAdjustment);

        return response()->json(['data' => $this->present($result, $request)]);
    }

    public function reverse(ReverseFinancialAdjustmentRequest $request, FinancialAdjustment $financialAdjustment): JsonResponse
    {
        try {
            $result = $this->service->reverse($financialAdjustment, $request->validated(), $request->user(), $request->ip());
        } catch (InvalidArgumentException $exception) {
            $status = str_contains($exception->getMessage(), 'idempotencia') ? 409 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json(['data' => $this->present($result['adjustment'], $request), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function present(FinancialAdjustment $adjustment, Request $request): array
    {
        return [
            ...$adjustment->toArray(),
            'capabilities' => [
                'can_view' => $request->user()?->canManageFinancialAdjustments() === true,
                'can_reverse' => $request->user()?->canManageFinancialAdjustments() === true
                    && ! $adjustment->is_legacy
                    && $adjustment->status === FinancialAdjustment::STATUS_COMPLETED
                    && ! $adjustment->reversal_of_id
                    && ! $adjustment->reversed_by_adjustment_id,
            ],
        ];
    }
}
