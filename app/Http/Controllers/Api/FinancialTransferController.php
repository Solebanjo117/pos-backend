<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReverseFinancialTransferRequest;
use App\Http\Requests\StoreFinancialTransferRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Models\PosSession;
use App\Services\FinancialTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialTransferController extends Controller
{
    public function __construct(private FinancialTransferService $transferService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:completed,reversed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transfers = FinancialTransfer::query()
            ->with(['creator:id,name', 'reversalOf:id,folio', 'reversedBy:id,folio'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('business_datetime', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('business_datetime', '<=', $date))
            ->orderByDesc('business_datetime')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $transfers->getCollection()->transform(fn (FinancialTransfer $transfer) => $this->present($transfer));

        return response()->json([
            'success' => true,
            'data' => $transfers->items(),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'total' => $transfers->total(),
            ],
        ]);
    }

    public function store(StoreFinancialTransferRequest $request): JsonResponse
    {
        $data = $request->safe()->except('receipt');
        $storedPath = null;

        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $storedPath = $file->store('financial-transfer-receipts', 'local');
            $data += [
                'receipt_path' => $storedPath,
                'receipt_original_name' => $file->getClientOriginalName(),
                'receipt_mime' => $file->getMimeType(),
                'receipt_size' => $file->getSize(),
            ];
        }

        try {
            $result = $this->transferService->create($data, $request->user());
        } catch (InvalidArgumentException $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            $status = str_contains($exception->getMessage(), 'idempotencia') ? 409 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => $result['replayed'] ? 'Transferencia recuperada.' : 'Transferencia completada.',
            'data' => $this->present($result['transfer']),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function show(FinancialTransfer $financialTransfer): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->present($financialTransfer->load(['creator:id,name', 'reversalOf:id,folio', 'reversedBy:id,folio'])),
        ]);
    }

    public function reverse(ReverseFinancialTransferRequest $request, FinancialTransfer $financialTransfer): JsonResponse
    {
        try {
            $reversal = $this->transferService->reverse($financialTransfer, $request->user(), $request->string('reason')->toString());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transferencia reversada.',
            'data' => $this->present($reversal),
        ], 201);
    }

    public function receipt(FinancialTransfer $financialTransfer): StreamedResponse
    {
        abort_unless($financialTransfer->receipt_path, 404);

        return Storage::disk('local')->download(
            $financialTransfer->receipt_path,
            $financialTransfer->receipt_original_name,
            ['Content-Type' => $financialTransfer->receipt_mime],
        );
    }

    /** @return array<string, mixed> */
    private function present(FinancialTransfer $transfer): array
    {
        return [
            ...$transfer->toArray(),
            'from_account' => $this->endpointLabel($transfer->from_endpoint_type, $transfer->from_endpoint_id),
            'to_account' => $this->endpointLabel($transfer->to_endpoint_type, $transfer->to_endpoint_id),
            'has_receipt' => (bool) $transfer->receipt_path,
        ];
    }

    /** @return array{id: int, name: string, type: string} */
    private function endpointLabel(string $type, int $id): array
    {
        if ($type === FinancialTransfer::ENDPOINT_FINANCIAL_ACCOUNT) {
            $account = FinancialAccount::query()->find($id);

            return ['id' => $id, 'name' => $account?->name ?? 'Cuenta eliminada', 'type' => $account?->type ?? 'other'];
        }

        $session = PosSession::query()->with('user:id,name')->find($id);

        return ['id' => $id, 'name' => 'Caja #'.$id.' · '.($session?->user?->name ?? 'Usuario'), 'type' => 'cash'];
    }
}
