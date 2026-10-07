<?php

namespace App\Console\Commands;

use App\Services\PayWay\PayWayReconciler;
use Illuminate\Console\Command;

/**
 * Settle PENDING transactions that never received a server-to-server callback.
 *
 * Useful when the callback host is not reachable from the public internet, or
 * to recover after the callback URL has been fixed and older transactions
 * still need confirming.
 */
class ReconcilePayWayTransactions extends Command
{
    protected $signature = 'payway:reconcile {--limit=200 : Maximum transactions to check}';

    protected $description = 'Ask PayWay for the result of every PENDING transaction and settle the paid ones';

    public function handle(PayWayReconciler $reconciler): int
    {
        $result = $reconciler->reconcilePending(max(1, (int) $this->option('limit')));

        $this->info("Checked {$result['checked']} pending transaction(s).");
        $this->info("Settled {$result['settled']} as paid.");

        return self::SUCCESS;
    }
}
