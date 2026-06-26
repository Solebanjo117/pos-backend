<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Driver-agnostic approach to downgrade falsely 'paid' credit orders
        $orders = DB::table('sales_orders')
            ->where('payment_status', 'paid')
            ->get();

        foreach ($orders as $order) {
            $liquidPaid = DB::table('sales_order_payments')
                ->where('sales_order_id', $order->id)
                ->where('method', '!=', 'credit')
                ->sum('amount');

            if ($order->total > $liquidPaid) {
                // Determine new status
                $newStatus = $liquidPaid > 0 ? 'partial' : 'pending';
                DB::table('sales_orders')
                    ->where('id', $order->id)
                    ->update(['payment_status' => $newStatus]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $orders = DB::table('sales_orders')
            ->whereIn('payment_status', ['pending', 'partial'])
            ->get();

        foreach ($orders as $order) {
            $allPaid = DB::table('sales_order_payments')
                ->where('sales_order_id', $order->id)
                ->sum('amount');

            if ($allPaid >= $order->total) {
                DB::table('sales_orders')
                    ->where('id', $order->id)
                    ->update(['payment_status' => 'paid']);
            }
        }
    }
};
