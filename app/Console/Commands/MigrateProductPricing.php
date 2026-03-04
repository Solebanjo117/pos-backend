<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\ProductPricing;

class MigrateProductPricing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-product-pricing';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates old variant pricings to product-level pricings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $products = Product::with(['variants.pricing'])->get();
        $migrated = 0;
        
        foreach ($products as $product) {
            $hasPricing = ProductPricing::where('product_id', $product->id)->exists();
            if ($hasPricing) continue;
        
            $firstPricing = null;
            foreach ($product->variants as $variant) {
                if ($variant->pricing) {
                    $firstPricing = $variant->pricing;
                    break;
                }
            }
        
            // Assign the first valid pricing to the product globally
            if ($firstPricing) {
                $firstPricing->product_id = $product->id;
                $firstPricing->variant_id = null; // Unlink from specific variant to avoid integrity constraint violations locally
                $firstPricing->save();
                $migrated++;
            }
        }
        
        // Remove duplicate/stale variant-level pricings
        $deleted = ProductPricing::whereNull('product_id')->delete();
        
        $this->info("Migrated $migrated product pricings. Deleted $deleted obsolete variant pricings.");
    }
}

