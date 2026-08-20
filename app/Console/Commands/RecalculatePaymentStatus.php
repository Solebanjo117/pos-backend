<?php

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use Illuminate\Console\Command;

class RecalculatePaymentStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:recalculate-payment-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate payment_status for all sales orders based on actual payments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Recalculating payment_status for all sales orders...');

        $orders = SalesOrder::with('payments')->get();
        $updated = 0;

        foreach ($orders as $order) {
            $oldStatus = $order->payment_status;

            // Calculate amount paid excluding deferred methods that are not real money.
            $amountPaid = (float) $order->payments()
                ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
                ->sum('amount');

            // Determine correct payment_status
            if ($amountPaid <= 0) {
                $order->payment_status = 'pending';
            } elseif ($amountPaid >= (float) $order->total) {
                $order->payment_status = 'paid';
            } else {
                $order->payment_status = 'partial';
            }

            // Only save if changed
            if ($oldStatus !== $order->payment_status) {
                $order->save();
                $updated++;
                $this->line("Order #{$order->order_number}: {$oldStatus} → {$order->payment_status}");
            }
        }

        $this->info("Done! Updated {$updated} orders.");

        return 0;
    }
}
