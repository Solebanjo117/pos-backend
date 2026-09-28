<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReconcileWarehouseCashRequest;
use App\Services\WarehouseCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseCashController extends Controller
{
    public function index(Request $request, WarehouseCashService $service): JsonResponse
    {
        return response()->json(['data' => $service->availability($request->integer('warehouse_id'))]);
    }

    public function store(ReconcileWarehouseCashRequest $request, WarehouseCashService $service): JsonResponse
    {
        return response()->json(['data' => $service->reconcile($request->integer('warehouse_id'), $request->validated(), $request->user())], 201);
    }
}
