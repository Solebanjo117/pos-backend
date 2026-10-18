<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCashDepositSourcesRequest;
use App\Http\Requests\StoreCashDepositRequest;
use App\Services\CashDepositService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class CashDepositController extends Controller
{
    public function __construct(private CashDepositService $deposits) {}

    public function sources(IndexCashDepositSourcesRequest $request): JsonResponse
    {
        return response()->json(['success' => true, ...$this->deposits->sources($request->validated(), $request->user())]);
    }

    public function store(StoreCashDepositRequest $request): JsonResponse
    {
        try {
            $result = $this->deposits->create($request->validated(), $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], str_contains($exception->getMessage(), 'idempotencia') ? 409 : 422);
        }

        return response()->json(['success' => true, 'data' => $result['transfer'], 'replayed' => $result['replayed'],
            'message' => $result['replayed'] ? 'Depósito recuperado.' : 'Depósito registrado.'], $result['replayed'] ? 200 : 201);
    }
}
