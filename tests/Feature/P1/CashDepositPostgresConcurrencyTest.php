<?php

use App\Models\CashDepositAllocation;
use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseCashService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes overlapping deposits idempotent retries and reversals on a disposable postgres database', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL descartable confirmado explícitamente.');
    }
    $database = DB::connection()->getDatabaseName();
    expect(app()->environment('testing'))->toBeTrue()
        ->and($database)->toStartWith('codex_tanda3_test_')
        ->and(getenv('HERMEX_DISPOSABLE_DATABASE'))->toBe($database);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $warehouse = Warehouse::create(['code' => 'DEPOSIT-CONCURRENT', 'name' => 'Sucursal descartable']);
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $warehouse->id]);
    auth()->login($user);
    $session = PosSession::create(['warehouse_id' => $warehouse->id, 'user_id' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'opened_at' => now()]);
    app(WarehouseCashService::class)->reconcile($warehouse->id, ['amount' => 200, 'cutoff_at' => now()->subDay(), 'reason' => 'Prueba descartable'], $user);
    PaymentMethod::updateOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'liquidity_bucket' => 'cash', 'is_active' => true]);
    $bank = FinancialAccount::create(['name' => 'Banco descartable', 'type' => 'bank', 'currency' => 'USD', 'current_balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'ledger_account_id' => LedgerAccount::where('code', '1110')->firstOrFail()->id]);
    $order = SalesOrder::create(['user_id' => $user->id, 'pos_session_id' => $session->id, 'status' => 'confirmed', 'date' => now(), 'total' => 100]);
    $source = SalesOrderPayment::create(['sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'pos_session_id' => $session->id, 'method' => 'cash', 'amount' => 100, 'paid_at' => now(), 'business_datetime' => now(), 'created_by' => $user->id]);
    $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = json_decode(base64_decode(getenv('REPORT_TEST_CONNECTION')), true);
if (!str_starts_with($connection['database'], 'codex_tanda3_test_') || getenv('HERMEX_DISPOSABLE_DATABASE') !== $connection['database']) { exit(7); }
config(['database.default'=>'pgsql', 'database.connections.pgsql'=>$connection]);
Illuminate\Support\Facades\DB::purge('pgsql');
$user = App\Models\User::findOrFail((int) getenv('REPORT_TEST_USER'));
auth()->login($user);
echo "READY\n";
try {
    $payload = json_decode(getenv('REPORT_TEST_PAYLOAD'), true);
    if (isset($payload['reverse_id'])) {
        app(App\Services\FinancialTransferService::class)->reverse(App\Models\FinancialTransfer::findOrFail($payload['reverse_id']), $user, 'Prueba concurrente');
        echo 'CREATED';
    } else {
        $result = app(App\Services\CashDepositService::class)->create($payload, $user);
        echo $result['replayed'] ? 'REPLAY' : 'CREATED';
    }
} catch (InvalidArgumentException|Illuminate\Validation\ValidationException $e) { echo 'REJECTED'; }
PHP;
    $run = function (array $requests) use ($worker, $user, $warehouse, $database): array {
        $processes = [];
        DB::beginTransaction();
        Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($requests as $request) {
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'HERMEX_DISPOSABLE_DATABASE' => $database,
                    'REPORT_TEST_CONNECTION' => base64_encode(json_encode(config('database.connections.pgsql'))), 'REPORT_TEST_USER' => (string) $user->id, 'REPORT_TEST_PAYLOAD' => json_encode($request)]);
                $process->setTimeout(30)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = collect($processes)->every(fn ($process) => str_contains($process->getOutput(), 'READY'));
                if (! $ready) {
                    usleep(10000);
                }
            } while (! $ready && microtime(true) < $deadline);
            expect($ready)->toBeTrue();
        } finally {
            DB::commit();
        }

        return array_map(function ($process): string {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

            return trim(str_replace('READY', '', $process->getOutput()));
        }, $processes);
    };
    $payload = ['warehouse_id' => $warehouse->id, 'financial_account_id' => $bank->id, 'idempotency_key' => (string) Str::uuid(),
        'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString(), 'amount' => 70, 'allocations' => [['scope' => 'sales', 'id' => $source->id, 'amount' => 70]]];
    $results = $run([$payload, [...$payload, 'idempotency_key' => (string) Str::uuid()]]);
    sort($results);
    expect($results)->toBe(['CREATED', 'REJECTED'])->and(CashDepositAllocation::count())->toBe(1)
        ->and(app(WarehouseCashService::class)->availability($warehouse->id)['balance'])->toBe(230.0)
        ->and((float) $bank->fresh()->current_balance)->toBe(70.0);
    $original = FinancialTransfer::firstOrFail();
    $payload['idempotency_key'] = $original->idempotency_key;
    expect($run([$payload, $payload]))->toBe(['REPLAY', 'REPLAY']);
    $results = $run([['reverse_id' => $original->id], ['reverse_id' => $original->id]]);
    sort($results);
    expect($results)->toBe(['CREATED', 'REJECTED'])->and(CashDepositAllocation::whereNull('released_at')->count())->toBe(0)
        ->and(app(WarehouseCashService::class)->availability($warehouse->id)['balance'])->toBe(300.0);
    $payload['idempotency_key'] = (string) Str::uuid();
    $results = $run([$payload, $payload]);
    sort($results);
    expect($results)->toBe(['CREATED', 'REPLAY'])->and(CashDepositAllocation::whereNull('released_at')->sum('amount'))->toEqual(70)
        ->and(FinancialTransfer::count())->toBe(3);
});
