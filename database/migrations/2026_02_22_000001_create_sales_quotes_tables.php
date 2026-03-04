<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotes', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('quote_number', 50)->unique();

            // References
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Dates
            $table->date('date');
            $table->date('valid_until')->nullable();

            // Status: draft → sent → accepted → converted | rejected | expired
            $table->string('status', 20)->default('draft');

            // Financial data
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            // Conversion tracking
            $table->foreignId('converted_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();

            // Additional info
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('quote_number');
            $table->index('customer_id');
            $table->index('status');
            $table->index('date');
        });

        Schema::create('sales_quote_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_quote_id')->constrained('sales_quotes')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->foreignId('warehouse_id')->constrained('warehouses');

            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);

            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);

            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);

            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);

            $table->timestamps();

            $table->index('sales_quote_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_items');
        Schema::dropIfExists('sales_quotes');
    }
};
