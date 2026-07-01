<?php

namespace App\Services;

use App\Models\ImportRunRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductImportBulkWriter
{
    /**
     * @param  array<int, array{
     *     key: string,
     *     product_key: string,
     *     product: array{sku_base: string, name: string, description: ?string, image_url: ?string, category_id: int},
     *     variant: array{
     *         brand_id: int,
     *         sku: string,
     *         system_id: ?string,
     *         pricing: ?array{base_price: float, cost_base: float, tax_rate: int},
     *         inventory: ?array{warehouse_id: int, quantity: int}
     *     }
     * }>  $rows
     * @return array<string, array{product_id: int, variant_id: int}>
     */
    public function write(array $rows, ?int $importRunId = null): array
    {
        if ($rows === []) {
            return [];
        }

        return DB::transaction(function () use ($rows, $importRunId): array {
            $now = now();
            $groupedRows = collect($rows)->groupBy('product_key');
            $productSystemIds = $this->reserveSystemIds('product_system_id', $groupedRows->count(), 'products');
            $productRows = [];
            $productSystemIdByKey = [];

            foreach ($groupedRows as $productKey => $productGroupRows) {
                $firstRow = $productGroupRows->first();
                $product = $firstRow['product'];
                $systemId = array_shift($productSystemIds);
                $productSystemIdByKey[(string) $productKey] = $systemId;
                $productRows[] = [
                    'system_id' => $systemId,
                    'sku_base' => $product['sku_base'],
                    'alternate_code' => null,
                    'pricing_formula' => null,
                    'product_line_id' => null,
                    'category_id' => $product['category_id'],
                    'name' => $product['name'],
                    'description' => $product['description'] ?: $product['name'],
                    'image_url' => $product['image_url'],
                    'status' => 'active',
                    'batch_tracking' => false,
                    'is_universal' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('products')->insert($productRows);

            $productIdsBySystemId = DB::table('products')
                ->whereIn('system_id', array_values($productSystemIdByKey))
                ->pluck('id', 'system_id');
            $missingVariantSystemIds = collect($rows)
                ->filter(fn (array $row): bool => $row['variant']['system_id'] === null)
                ->count();
            $reservedVariantSystemIds = $this->reserveSystemIds(
                'variant_system_id',
                $missingVariantSystemIds,
                'product_variants'
            );
            $variantRows = [];
            $variantSystemIdByKey = [];

            foreach ($rows as $row) {
                $productSystemId = $productSystemIdByKey[$row['product_key']];
                $productId = (int) $productIdsBySystemId[$productSystemId];
                $variantSystemId = $row['variant']['system_id'] ?? array_shift($reservedVariantSystemIds);
                $variantSystemIdByKey[$row['key']] = $variantSystemId;
                $variantRows[] = [
                    'product_id' => $productId,
                    'brand_id' => $row['variant']['brand_id'],
                    'sku' => $row['variant']['sku'],
                    'upc' => null,
                    'status' => 'active',
                    'is_default' => false,
                    'system_id' => $variantSystemId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
            }

            DB::table('product_variants')->insert($variantRows);

            $variantIdsBySystemId = DB::table('product_variants')
                ->whereIn('system_id', array_values($variantSystemIdByKey))
                ->pluck('id', 'system_id');
            $results = [];
            $pricingRows = [];
            $inventoryRows = [];
            $inventoryPayloads = [];

            foreach ($rows as $row) {
                $productSystemId = $productSystemIdByKey[$row['product_key']];
                $productId = (int) $productIdsBySystemId[$productSystemId];
                $variantSystemId = $variantSystemIdByKey[$row['key']];
                $variantId = (int) $variantIdsBySystemId[$variantSystemId];
                $results[$row['key']] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                ];

                if ($row['variant']['pricing'] !== null) {
                    $pricing = $row['variant']['pricing'];
                    $costTotal = round($pricing['cost_base'] * (1 + ($pricing['tax_rate'] / 100)), 2);
                    $margin = $pricing['base_price'] > 0
                        ? (($pricing['base_price'] - $costTotal) / $pricing['base_price']) * 100
                        : 0;
                    $pricingRows[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'base_price' => $pricing['base_price'],
                        'msrp' => 0,
                        'cost' => $costTotal,
                        'cost_base' => $pricing['cost_base'],
                        'cost_miscellaneous' => 0,
                        'margin_percentage' => $margin,
                        'tax_rate' => $pricing['tax_rate'],
                        'sale_tax_rate' => $pricing['tax_rate'],
                        'cost_tax_rate' => $pricing['tax_rate'],
                        'tax_type' => 'standard',
                        'max_discount_percentage' => 0,
                        'pricing_formula' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($row['variant']['inventory'] !== null) {
                    $inventory = $row['variant']['inventory'];
                    $pricing = $row['variant']['pricing'];
                    $costTotal = $pricing === null
                        ? 0
                        : round($pricing['cost_base'] * (1 + ($pricing['tax_rate'] / 100)), 2);
                    $inventoryRows[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'warehouse_id' => $inventory['warehouse_id'],
                        'quantity_available' => $inventory['quantity'],
                        'quantity_reserved' => 0,
                        'reorder_point' => 0,
                        'reorder_quantity' => 0,
                        'location_in_warehouse' => null,
                        'last_restocked_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $inventoryPayloads[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'warehouse_id' => $inventory['warehouse_id'],
                        'quantity' => $inventory['quantity'],
                        'unit_cost' => $costTotal,
                        'sale_price' => $pricing['base_price'] ?? 0,
                    ];
                }
            }

            if ($pricingRows !== []) {
                DB::table('product_pricing')->insert($pricingRows);
            }

            if ($inventoryRows !== []) {
                DB::table('product_inventory')->insert($inventoryRows);
                $this->writeOpeningLots($inventoryPayloads, $now);
            }

            if ($importRunId !== null) {
                $this->writeImportRecords($importRunId, $rows, $results, $now);
            }

            return $results;
        });
    }

    /**
     * @param  array<int, array{product_id: int, variant_id: int, warehouse_id: int, quantity: int, unit_cost: float, sale_price: float}>  $payloads
     */
    private function writeOpeningLots(array $payloads, mixed $now): void
    {
        $movementRows = array_map(fn (array $payload): array => [
            'movement_type' => 'in',
            'reason' => 'opening_balance',
            'product_id' => $payload['product_id'],
            'variant_id' => $payload['variant_id'],
            'warehouse_id' => $payload['warehouse_id'],
            'destination_warehouse_id' => null,
            'quantity' => $payload['quantity'],
            'reference_type' => 'opening_balance',
            'reference_id' => null,
            'notes' => 'Saldo inicial FIFO',
            'user_id' => Auth::id(),
            'supplier_id' => null,
            'product_batch_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $payloads);

        DB::table('inventory_movements')->insert($movementRows);

        $movementIds = DB::table('inventory_movements')
            ->whereIn('variant_id', array_column($payloads, 'variant_id'))
            ->where('reason', 'opening_balance')
            ->get(['id', 'variant_id', 'warehouse_id'])
            ->keyBy(fn (object $movement): string => $movement->variant_id.'|'.$movement->warehouse_id);
        $lotRows = [];

        foreach ($payloads as $payload) {
            $movement = $movementIds[$payload['variant_id'].'|'.$payload['warehouse_id']];
            $lotRows[] = [
                'product_id' => $payload['product_id'],
                'variant_id' => $payload['variant_id'],
                'warehouse_id' => $payload['warehouse_id'],
                'source_type' => 'opening_balance',
                'source_id' => null,
                'source_movement_id' => $movement->id,
                'batch_number' => null,
                'received_quantity' => $payload['quantity'],
                'quantity_available' => $payload['quantity'],
                'unit_cost_base' => $payload['unit_cost'],
                'tax_per_unit' => 0,
                'misc_per_unit' => 0,
                'landed_unit_cost' => $payload['unit_cost'],
                'sale_price' => $payload['sale_price'],
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('inventory_lots')->insert($lotRows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array{product_id: int, variant_id: int}>  $results
     */
    private function writeImportRecords(int $importRunId, array $rows, array $results, mixed $now): void
    {
        $records = [];

        foreach ($rows as $row) {
            $result = $results[$row['key']];
            $records[$result['product_id'].'|product'] = [
                'import_run_id' => $importRunId,
                'model_type' => Product::class,
                'model_id' => $result['product_id'],
                'action' => 'created',
                'summary' => json_encode(['name' => $row['product']['name']], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $records[$result['variant_id'].'|variant'] = [
                'import_run_id' => $importRunId,
                'model_type' => ProductVariant::class,
                'model_id' => $result['variant_id'],
                'action' => 'created',
                'summary' => json_encode(['sku' => $row['variant']['sku']], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ImportRunRecord::query()->insertOrIgnore(array_values($records));
    }

    /**
     * @return array<int, string>
     */
    private function reserveSystemIds(string $key, int $count, string $table): array
    {
        if ($count <= 0) {
            return [];
        }

        $sequence = DB::table('system_sequences')
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            DB::table('system_sequences')->insertOrIgnore([
                'key' => $key,
                'next_value' => $this->maxNumericSystemId($table) + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = DB::table('system_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();
        }

        $firstValue = (int) $sequence->next_value;
        DB::table('system_sequences')
            ->where('key', $key)
            ->update([
                'next_value' => $firstValue + $count,
                'updated_at' => now(),
            ]);

        return collect(range($firstValue, $firstValue + $count - 1))
            ->map(fn (int $value): string => str_pad((string) $value, 8, '0', STR_PAD_LEFT))
            ->all();
    }

    private function maxNumericSystemId(string $table): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return (int) (DB::table($table)
                ->whereRaw("system_id ~ '^[0-9]+$'")
                ->selectRaw('MAX(CAST(system_id AS BIGINT)) AS max_system_id')
                ->value('max_system_id') ?? 0);
        }

        return (int) (DB::table($table)
            ->whereRaw("system_id NOT GLOB '*[^0-9]*'")
            ->selectRaw('MAX(CAST(system_id AS INTEGER)) AS max_system_id')
            ->value('max_system_id') ?? 0);
    }
}
