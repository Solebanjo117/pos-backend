<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FinancialTransferService
{
    public function __construct(
        private DocumentSequenceService $documentSequenceService,
        private FinancialAccountMovementService $movementService,
        private LedgerPostingService $ledgerPostingService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{transfer: FinancialTransfer, replayed: bool}
     */
    public function create(array $data, User $user): array
    {
        $requestHash = $this->requestHash($data);
        $existing = FinancialTransfer::query()->where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new InvalidArgumentException('La clave de idempotencia ya fue utilizada con otros datos.');
            }

            return ['transfer' => $this->loadTransfer($existing), 'replayed' => true];
        }

        try {
            $transfer = DB::transaction(function () use ($data, $user, $requestHash): FinancialTransfer {
                [$from, $fromBalance] = $this->lockEndpoint($data['from_endpoint_type'], (int) $data['from_endpoint_id']);
                [$to, $toBalance] = $this->lockEndpoint($data['to_endpoint_type'], (int) $data['to_endpoint_id']);
                $amount = round((float) $data['amount'], 2);

                if ($this->endpointKey($data['from_endpoint_type'], $from->getKey()) === $this->endpointKey($data['to_endpoint_type'], $to->getKey())) {
                    throw new InvalidArgumentException('La cuenta de origen y destino deben ser diferentes.');
                }

                if ($fromBalance < $amount && ! ($from instanceof FinancialAccount && $from->allow_negative_balance)) {
                    throw new InvalidArgumentException('La cuenta de origen no tiene saldo suficiente.');
                }

                $businessDatetime = $user->isAdmin() && ! empty($data['business_datetime'])
                    ? $data['business_datetime']
                    : now();

                $transfer = FinancialTransfer::create([
                    ...$data,
                    'folio' => $this->documentSequenceService->nextFinancialTransferFolio(),
                    'request_hash' => $requestHash,
                    'amount' => $amount,
                    'status' => FinancialTransfer::STATUS_COMPLETED,
                    'business_datetime' => $businessDatetime,
                    'created_by' => $user->id,
                ]);

                $this->recordEndpointMovement($from, 'out', $amount, $fromBalance, $transfer, $user);
                $this->recordEndpointMovement($to, 'in', $amount, $toBalance, $transfer, $user);
                $this->postJournal($transfer, $from, $to, $user);

                activity()
                    ->performedOn($transfer)
                    ->causedBy($user)
                    ->withProperties(['folio' => $transfer->folio, 'amount' => $amount])
                    ->log('Transferencia financiera completada');

                return $transfer;
            }, 3);
        } catch (QueryException $exception) {
            $existing = FinancialTransfer::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if (! $existing) {
                throw $exception;
            }
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new InvalidArgumentException('La clave de idempotencia ya fue utilizada con otros datos.');
            }

            return ['transfer' => $this->loadTransfer($existing), 'replayed' => true];
        }

        return ['transfer' => $this->loadTransfer($transfer), 'replayed' => false];
    }

    public function reverse(FinancialTransfer $original, User $user, ?string $reason = null): FinancialTransfer
    {
        if ($original->status !== FinancialTransfer::STATUS_COMPLETED || $original->reversal_of_id) {
            throw new InvalidArgumentException('Esta transferencia no puede reversarse.');
        }

        return DB::transaction(function () use ($original, $user, $reason): FinancialTransfer {
            $locked = FinancialTransfer::query()->lockForUpdate()->findOrFail($original->id);
            if ($locked->reversed_by_transfer_id) {
                throw new InvalidArgumentException('La transferencia ya fue reversada.');
            }

            $result = $this->create([
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'from_endpoint_type' => $locked->to_endpoint_type,
                'from_endpoint_id' => $locked->to_endpoint_id,
                'to_endpoint_type' => $locked->from_endpoint_type,
                'to_endpoint_id' => $locked->from_endpoint_id,
                'amount' => (float) $locked->amount,
                'reference' => 'Reversa de '.$locked->folio,
                'notes' => $reason,
                'business_datetime' => now(),
                'reversal_of_id' => $locked->id,
            ], $user);

            $reversal = $result['transfer'];
            $locked->update([
                'status' => FinancialTransfer::STATUS_REVERSED,
                'reversed_by_transfer_id' => $reversal->id,
            ]);

            return $this->loadTransfer($reversal);
        }, 3);
    }

    /**
     * @return array{0: Model, 1: float}
     */
    private function lockEndpoint(string $type, int $id): array
    {
        if ($type === FinancialTransfer::ENDPOINT_FINANCIAL_ACCOUNT) {
            $account = FinancialAccount::query()->with('ledgerAccount')->lockForUpdate()->findOrFail($id);
            if (! $account->is_active) {
                throw new InvalidArgumentException('La cuenta financiera está inactiva.');
            }

            return [$account, round((float) $account->current_balance, 2)];
        }

        if ($type === FinancialTransfer::ENDPOINT_POS_SESSION) {
            $session = PosSession::query()->with(['warehouse', 'user'])->lockForUpdate()->findOrFail($id);
            if ($session->status !== 'open') {
                throw new InvalidArgumentException('La sesión de caja está cerrada.');
            }

            $in = (float) CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'in')->sum('amount');
            $out = (float) CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'out')->sum('amount');

            return [$session, round((float) $session->opening_amount + $in - $out, 2)];
        }

        throw new InvalidArgumentException('El tipo de cuenta no es válido.');
    }

    private function recordEndpointMovement(
        Model $endpoint,
        string $direction,
        float $amount,
        float $balanceBefore,
        FinancialTransfer $transfer,
        User $user,
    ): void {
        if ($endpoint instanceof FinancialAccount) {
            $this->movementService->record($endpoint, $direction, $amount, [
                'source_type' => 'financial_transfer',
                'source_id' => $transfer->id,
                'source_line_key' => $direction,
                'business_datetime' => $transfer->business_datetime,
                'reference' => $transfer->reference,
                'notes' => $transfer->notes,
                'created_by' => $user->id,
            ]);

            return;
        }

        CashMovement::create([
            'pos_session_id' => $endpoint->id,
            'user_id' => $user->id,
            'type' => $direction,
            'subtype' => 'financial_transfer',
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => round($balanceBefore + ($direction === 'in' ? $amount : -$amount), 2),
            'reason' => 'Transferencia '.$transfer->folio,
            'reference_number' => $transfer->reference,
            'payment_method' => 'cash',
            'notes' => $transfer->notes,
            'source_type' => 'financial_transfer',
            'source_id' => $transfer->id,
            'business_datetime' => $transfer->business_datetime,
        ]);
    }

    private function postJournal(FinancialTransfer $transfer, Model $from, Model $to, User $user): void
    {
        $fromLedger = $this->ledgerAccountForEndpoint($from);
        $toLedger = $this->ledgerAccountForEndpoint($to);

        $this->ledgerPostingService->postManualEntry([
            'source_type' => 'financial_transfer',
            'source_id' => $transfer->id,
            'posting_key' => 'financial_transfer:'.$transfer->id,
            'occurred_at' => $transfer->business_datetime,
            'memo' => 'Transferencia '.$transfer->folio,
            'created_by' => $user->id,
            'lines' => [
                [
                    'account_code' => $toLedger->code,
                    'account_name' => $toLedger->name,
                    'debit' => (float) $transfer->amount,
                    'credit' => 0,
                    'metadata' => ['endpoint' => $transfer->to_endpoint_type, 'endpoint_id' => $transfer->to_endpoint_id],
                ],
                [
                    'account_code' => $fromLedger->code,
                    'account_name' => $fromLedger->name,
                    'debit' => 0,
                    'credit' => (float) $transfer->amount,
                    'metadata' => ['endpoint' => $transfer->from_endpoint_type, 'endpoint_id' => $transfer->from_endpoint_id],
                ],
            ],
        ]);
    }

    private function ledgerAccountForEndpoint(Model $endpoint): LedgerAccount
    {
        if ($endpoint instanceof FinancialAccount) {
            return $endpoint->ledgerAccount;
        }

        return LedgerAccount::firstOrCreate(
            ['code' => '1100'],
            ['name' => 'Cash', 'type' => 'asset', 'is_active' => true],
        );
    }

    /** @param array<string, mixed> $data */
    private function requestHash(array $data): string
    {
        $normalized = collect($data)->except([
            'receipt_path',
            'receipt_original_name',
            'receipt_mime',
            'receipt_size',
            'reversal_of_id',
        ])->sortKeys()->all();

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function endpointKey(string $type, int|string $id): string
    {
        return $type.':'.$id;
    }

    private function loadTransfer(FinancialTransfer $transfer): FinancialTransfer
    {
        return $transfer->load(['creator:id,name', 'reversalOf:id,folio', 'reversedBy:id,folio']);
    }
}
