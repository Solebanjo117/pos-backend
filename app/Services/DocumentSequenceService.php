<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Carbon\CarbonInterface;
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

    /**
     * Folio diario de orden de venta: YYMMDD + secuencia de 4 dígitos que se reinicia cada día.
     */
    public function nextSalesOrderFolio(CarbonInterface $regionalDatetime): string
    {
        $date = $regionalDatetime->format('ymd');

        return $date.str_pad((string) $this->next('sales_order:'.$date), 4, '0', STR_PAD_LEFT);
    }

    public function nextQuoteFolio(CarbonInterface $regionalDatetime): string
    {
        $date = $regionalDatetime->format('Ymd');
        $sequence = $this->next('sales_quote:'.$date);

        if ($sequence > 9999) {
            throw new \RuntimeException("Se agotaron los folios de cotización para {$date}.");
        }

        return $date.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function nextPurchaseOrderFolio(): string
    {
        return 'PO-'.str_pad((string) $this->next(self::PURCHASE_ORDER), 10, '0', STR_PAD_LEFT);
    }

    public function nextFinancialTransferFolio(): string
    {
        return 'TRF-'.str_pad((string) $this->next(self::FINANCIAL_TRANSFER), 10, '0', STR_PAD_LEFT);
    }

    public function nextFinancialAdjustmentFolio(CarbonInterface $businessDatetime): string
    {
        $year = $businessDatetime->format('Y');

        return 'AJ-'.$year.'-'.str_pad(
            (string) $this->next('financial_adjustment:'.$year),
            6,
            '0',
            STR_PAD_LEFT,
        );
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
