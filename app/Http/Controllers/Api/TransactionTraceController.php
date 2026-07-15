<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionTraceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionTraceController extends Controller
{
    public function show(Request $request, string $type, int $id, TransactionTraceService $service): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $service->get(
                $type,
                $id,
                $request->user(),
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 25),
            ),
        ]);
    }
}
