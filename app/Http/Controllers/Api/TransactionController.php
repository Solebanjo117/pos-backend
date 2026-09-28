<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTransactionsRequest;
use App\Services\TransactionListService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionListService $transactions) {}

    public function index(IndexTransactionsRequest $request): JsonResponse
    {
        return response()->json($this->transactions->list($request->validated(), $request->user()));
    }
}
