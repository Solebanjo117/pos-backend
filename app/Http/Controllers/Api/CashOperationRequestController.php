<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashOperationRequest;
use App\Services\CashOperationRequestService;
use Illuminate\Http\JsonResponse;

class CashOperationRequestController extends Controller
{
    public function __construct(private readonly CashOperationRequestService $service) {}

    public function store(StoreCashOperationRequest $request): JsonResponse
    {
        try {
            $result = $this->service->create($request->validated(), $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => $result['replayed'] ? 'Solicitud recuperada.' : 'Solicitud enviada a la cola de Caja.',
            'data' => $result['request'],
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }
}
