<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentSequenceService
{
    public const COMMERCIAL = 'commercial_document';

    public const PURCHASE_ORDER = 'purchase_order';

    public const FINANCIAL_TRANSFER = 'financial_transfer';

    public function nextCommercialFolio(): string
    {
        return str_pad((string) $this->next(self::COMMERCIAL), 10, '0', STR_PAD_LEFT);
    }

    public function nextPurchaseOrderFolio(): string
    {
        return 'PO-'.str_pad((string) $this->next(self::PURCHASE_ORDER), 10, '0', STR_PAD_LEFT);
    }

    public function nextFinancialTransferFolio(): string
    {
        return 'TRF-'.str_pad((string) $this->next(self::FINANCIAL_TRANSFER), 10, '0', STR_PAD_LEFT);
    }

    private function next(string $key): int
    {
        return DB::transaction(function () use ($key): int {
            DB::table('document_sequences')->insertOrIgnore([
                'sequence_key' => $key,
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::query()
                ->where('sequence_key', $key)
                ->lockForUpdate()
                ->firstOrFail();
            $sequence->increment('current_value');

            return (int) $sequence->fresh()->current_value;
        }, 3);
    }
}
