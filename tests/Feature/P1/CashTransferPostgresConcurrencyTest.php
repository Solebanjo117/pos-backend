<?php

use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseCashService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes concurrent cash transfers and replays without overdrawing an isolated postgres database', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL de pruebas aislado.');
    }
    expect(DB::connection()->getDatabaseName())->toStartWith('codex_tanda3_test_');
    Artisan::call('migrate:fresh', ['--force' => true]);
    $warehouse = Warehouse::create(['code' => 'CONCURRENT', 'name' => 'Prueba aislada']);
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $warehouse->id]);
    PosSession::create(['warehouse_id' => $warehouse->id, 'user_id' => $user->id, 'status' => 'open', 'opening_amount' => 100, 'opened_at' => now()]);
    app(WarehouseCashService::class)->reconcile($warehouse->id, ['amount' => 100, 'cutoff_at' => now()->subMinute(), 'reason' => 'Prueba'], $user);
    $account = FinancialAccount::create(['name' => 'Banco prueba', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true, 'ledger_account_id' => LedgerAccount::where('code', '1110')->firstOrFail()->id]);
    $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = json_decode(base64_decode(getenv('REPORT_TEST_CONNECTION')), true);
if (!str_starts_with($connection['database'], 'codex_tanda3_test_')) { exit(7); }
config(['database.default'=>'pgsql', 'database.connections.pgsql'=>$connection]);
Illuminate\Support\Facades\DB::purge('pgsql');
$user = App\Models\User::findOrFail((int) getenv('REPORT_TEST_USER'));
auth()->login($user);
echo "READY\n";
try {
    $result = app(App\Services\FinancialTransferService::class)->create(json_decode(getenv('REPORT_TEST_PAYLOAD'), true), $user);
    echo $result['replayed'] ? 'REPLAY' : 'CREATED';
} catch (InvalidArgumentException|Illuminate\Validation\ValidationException $e) { echo 'REJECTED'; }
PHP;
    $payload = ['idempotency_key' => (string) Str::uuid(), 'from_endpoint_type' => 'warehouse_cash', 'from_endpoint_id' => $warehouse->id, 'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => $account->id, 'amount' => 70];
    $run = function (array $requests) use ($worker, $user, $warehouse): array {
        $processes = [];
        DB::beginTransaction();
        Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($requests as $request) {
                $process = new Process([PHP_BINARY, '-d', 'extension=gd', '-r', $worker], base_path(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'REPORT_TEST_CONNECTION' => base64_encode(json_encode(config('database.connections.pgsql'))), 'REPORT_TEST_USER' => (string) $user->id, 'REPORT_TEST_PAYLOAD' => json_encode($request)]);
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

        return array_map(function ($process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

            return trim(str_replace('READY', '', $process->getOutput()));
        }, $processes);
    };
    $results = $run([$payload, [...$payload, 'idempotency_key' => (string) Str::uuid()]]);
    sort($results);
    expect($results)->toBe(['CREATED', 'REJECTED'])->and(FinancialTransfer::count())->toBe(1)
        ->and(app(WarehouseCashService::class)->availability($warehouse->id)['balance'])->toBe(30.0)
        ->and((float) $account->fresh()->current_balance)->toBe(70.0);
    $payload['idempotency_key'] = FinancialTransfer::firstOrFail()->idempotency_key;
    expect($run([$payload, $payload]))->toBe(['REPLAY', 'REPLAY'])->and(FinancialTransfer::count())->toBe(1);
});
