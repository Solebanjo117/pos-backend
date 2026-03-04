<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\ProductPricing;
use App\Models\ProductVariant;

class EnsureVariantPricing extends Command
{
    protected $signature = 'app:ensure-variant-pricing';
    protected $description = 'Ensures every product variant has its own independent pricing record';

    public function handle()
    {
        $variants = ProductVariant::with(['pricing'])
            ->whereNull('deleted_at')
            ->get();

        $created = 0;

        foreach ($variants as $variant) {
            // Skip if variant already has its own pricing record
            if ($variant->pricing) {
                continue;
            }

            // Find the product's pricing to copy
            $productPricing = ProductPricing::where('product_id', $variant->product_id)
                ->orderBy('id')
                ->first();

            if (!$productPricing) {
                $this->warn("Variant {$variant->id} (product {$variant->product_id}): no pricing found to copy, skipping.");
                continue;
            }

            ProductPricing::create([
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'base_price' => $productPricing->base_price,
                'msrp' => $productPricing->msrp,
                'cost' => $productPricing->cost,
                'tax_rate' => $productPricing->tax_rate,
                'margin_percentage' => $productPricing->margin_percentage,
                'max_discount_percentage' => $productPricing->max_discount_percentage,
            ]);

            $created++;
            $this->info("Created pricing for variant {$variant->id} ({$variant->sku})");
        }

        $this->info("Done. Created {$created} variant pricing records.");
    }
}
