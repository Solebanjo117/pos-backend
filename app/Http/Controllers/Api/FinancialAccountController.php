<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Http\Requests\UpdateFinancialAccountRequest;
use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = FinancialAccount::query()
            ->with('ledgerAccount:id,code,name')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $accounts]);
    }

    public function store(StoreFinancialAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 2);
        $account = FinancialAccount::create([
            ...$data,
            'opening_balance' => $openingBalance,
            'current_balance' => $openingBalance,
            'created_by' => $request->user()->id,
        ]);

        activity()->performedOn($account)->causedBy($request->user())->log('Cuenta financiera creada');

        return response()->json([
            'success' => true,
            'message' => 'Cuenta financiera creada.',
            'data' => $account->load('ledgerAccount:id,code,name'),
        ], 201);
    }

    public function update(UpdateFinancialAccountRequest $request, FinancialAccount $financialAccount): JsonResponse
    {
        if ($financialAccount->is_legacy && $request->boolean('is_active') === false) {
            return response()->json(['message' => 'La cuenta de compatibilidad histórica no puede desactivarse.'], 422);
        }

        $financialAccount->update($request->validated());
        activity()->performedOn($financialAccount)->causedBy($request->user())->log('Cuenta financiera actualizada');

        return response()->json([
            'success' => true,
            'message' => 'Cuenta financiera actualizada.',
            'data' => $financialAccount->fresh('ledgerAccount:id,code,name'),
        ]);
    }

    public function ledgerAccounts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => LedgerAccount::query()
                ->where('type', 'asset')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:cash,bank,petty_cash,other'],
            'purpose' => ['nullable', 'string', 'in:transfer,settlement'],
        ]);
        $type = $validated['type'] ?? null;
        $options = collect();

        if (($type === null || $type === 'cash') && ($validated['purpose'] ?? null) === 'transfer') {
            $warehouses = \App\Models\Warehouse::query()->when(! $request->user()->isAdmin(), fn ($query) => $query->whereIn('id', $request->user()->warehouses()->pluck('warehouses.id')))->get();
            foreach ($warehouses as $warehouse) {
                $available = app(\App\Services\WarehouseCashService::class)->availability($warehouse->id);
                $options->push(['endpoint_type' => 'warehouse_cash', 'endpoint_id' => $warehouse->id, 'account_type' => 'cash', 'name' => 'Efectivo · '.$warehouse->name, 'masked_number' => null, 'balance' => $available['balance'], 'initialized' => $available['initialized'], 'currency' => \App\Models\Setting::get('store_currency', 'USD'), 'allow_negative_balance' => false]);
            }
        }
        if (($type === null || $type === 'cash') && ($validated['purpose'] ?? null) !== 'transfer') {
            $sessions = PosSession::query()
                ->with(['user:id,name', 'warehouse:id,name'])
                ->where('status', 'open')
                ->when(
                    ($validated['purpose'] ?? null) === 'settlement',
                    fn ($query) => $query->where('user_id', $request->user()->id)
                )
                ->orderByDesc('opened_at')
                ->get();

            $options->push(...$sessions->map(function (PosSession $session): array {
                $in = (float) CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'in')->sum('amount');
                $out = (float) CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'out')->sum('amount');

                return [
                    'endpoint_type' => 'pos_session',
                    'endpoint_id' => $session->id,
                    'account_type' => 'cash',
                    'name' => 'Caja #'.$session->id.' · '.$session->user?->name,
                    'masked_number' => null,
                    'balance' => round((float) $session->opening_amount + $in - $out, 2),
                    'currency' => \App\Models\Setting::get('store_currency', 'USD'),
                    'allow_negative_balance' => false,
                ];
            }));
        }

        if ($type !== 'cash') {
            $accounts = FinancialAccount::query()
                ->where('is_active', true)
                ->when($type, fn ($query) => $query->where('type', $type))
                ->when(
                    ($validated['purpose'] ?? null) === 'settlement',
                    fn ($query) => $query->where('is_legacy', false)
                )
                ->orderBy('name')
                ->get();

            $options->push(...$accounts->map(fn (FinancialAccount $account): array => [
                'endpoint_type' => 'financial_account',
                'endpoint_id' => $account->id,
                'account_type' => $account->type,
                'name' => $account->name,
                'masked_number' => $account->masked_number,
                'balance' => round((float) $account->current_balance, 2),
                'currency' => $account->currency,
                'allow_negative_balance' => $account->allow_negative_balance,
            ]));
        }

        return response()->json(['success' => true, 'data' => $options->values()]);
    }
}
