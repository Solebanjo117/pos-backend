<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignPaymentAccountRequest;
use App\Http\Requests\IndexCashPaymentsRequest;
use App\Models\OperationalPayment;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrderPayment;
use App\Services\CashPaymentQueryService;
use App\Services\OperationalPaymentService;
use Illuminate\Http\JsonResponse;

class OperationalPaymentController extends Controller
{
    public function index(IndexCashPaymentsRequest $request, CashPaymentQueryService $service): JsonResponse
    {
        $result = $service->query($request->integer('warehouse_id'), $request->validated())->orderByDesc('movements.business_datetime')->orderByDesc('movements.id')->paginate($request->integer('per_page', 25))->toArray();
        $result['data'] = array_map(fn ($row) => $service->normalizeRow($row), $result['data']);
        $options = $service->query($request->integer('warehouse_id'), ['scope' => $request->input('scope', 'sales')]);
        $result['filter_options'] = [
            'methods' => \App\Models\PaymentMethod::query()->whereNotIn('code', \App\Models\PaymentMethod::DEFERRED_CODES)->ordered()->get(['code', 'name']),
            'operators' => (clone $options)->whereNotNull('movements.user_id')->select('movements.user_id as id', 'operator.name')->distinct()->get(),
            'sessions' => (clone $options)->whereNotNull('movements.pos_session_id')->select('movements.pos_session_id as id')->distinct()->get(),
            'warehouses' => \App\Models\Warehouse::query()->when(! $request->user()->isAdmin(), fn ($query) => $query->whereKey($request->integer('warehouse_id')))->get(['id', 'name']),
        ];
        $result['warnings'] = \App\Models\AccountPaymentBatch::query()->where('account_type', 'customer')->where('direction', 'incoming')->whereNull('warehouse_id')->whereNull('pos_session_id')->exists()
            ? ['Existen recibos históricos sin sucursal identificable. Revise su conciliación para completar los cobros por sucursal.'] : [];

        return response()->json($result);
    }

    public function summary(IndexCashPaymentsRequest $request, CashPaymentQueryService $service): JsonResponse
    {
        return response()->json(['data' => $service->summary($request->integer('warehouse_id'), $request->validated())]);
    }

    public function assign(string $scope, int $payment, AssignPaymentAccountRequest $request, OperationalPaymentService $service): JsonResponse
    {
        $model = match ($scope) {
            'operations' => OperationalPayment::query()->findOrFail($payment),
            'sales' => SalesOrderPayment::query()->findOrFail($payment),
            'purchases' => PurchaseOrderPayment::query()->findOrFail($payment),
            'receipts' => \App\Models\AccountPaymentBatch::query()->findOrFail($payment),
            default => abort(404),
        };

        return response()->json(['data' => $service->assignDocumentAccount($model, $request->integer('financial_account_id'), $request->user(), $request->integer('warehouse_id'))]);
    }
}
