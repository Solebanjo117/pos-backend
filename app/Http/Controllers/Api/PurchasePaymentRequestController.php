<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchasePaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchasePaymentRequest;
use App\Services\PurchasePaymentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchasePaymentRequestController extends Controller
{
    public function cancel(PurchaseOrder $purchaseOrder, PurchasePaymentRequest $paymentRequest, \App\Http\Requests\CancelPurchasePaymentRequest $request, \App\Services\CashOperationRequestService $service): JsonResponse
    {
        abort_unless((int) $paymentRequest->purchase_order_id === (int) $purchaseOrder->id, 404);

        return response()->json(['data' => $service->cancel($paymentRequest->queueEntry, $request->user(), $request->validated('reason'), $request->integer('warehouse_id'))]);
    }

    public function store(PurchaseOrder $purchaseOrder, StorePurchasePaymentRequest $request, PurchasePaymentRequestService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->create($purchaseOrder, $request->validated(), $request->user(), $request->integer('warehouse_id'))], 201);
    }

    public function index(PurchaseOrder $purchaseOrder, Request $request): JsonResponse
    {
        abort_unless((int) $purchaseOrder->warehouse_id === $request->integer('warehouse_id'), 403);

        return response()->json(['data' => PurchasePaymentRequest::query()->with('queueEntry')->where('purchase_order_id', $purchaseOrder->id)->latest()->get()]);
    }
}
