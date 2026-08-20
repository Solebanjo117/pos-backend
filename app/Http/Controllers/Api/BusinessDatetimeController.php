<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BusinessDatetimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessDatetimeController extends Controller
{
    public function __construct(private BusinessDatetimeService $businessDatetimeService) {}

    public function show(string $recordType, int $recordId): JsonResponse
    {
        return response()->json([
            'data' => $this->businessDatetimeService->detail($recordType, $recordId),
        ]);
    }

    public function update(Request $request, string $recordType, int $recordId): JsonResponse
    {
        $validated = $request->validate([
            'business_datetime' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'record_type' => ['sometimes', Rule::in($this->businessDatetimeService->allowedRecordTypes())],
        ]);

        return response()->json([
            'message' => 'Fecha/hora operativa actualizada correctamente.',
            'data' => $this->businessDatetimeService->update(
                $recordType,
                $recordId,
                $validated['business_datetime'],
                $validated['reason'],
                $request->user(),
            ),
        ]);
    }
}
