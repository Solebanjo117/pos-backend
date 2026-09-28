<?php

namespace App\Http\Controllers\Api;

use App\Exports\MoneyReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialReportRequest;
use App\Models\FinancialAccount;
use App\Services\CashPaymentQueryService;
use App\Services\CashPositionReportService;
use App\Services\FinancialAccountReportService;
use App\Services\SalesLineReportService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MoneyReportController extends Controller
{
    public function options(FinancialReportRequest $request, SalesLineReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->options($request->validated(), $request->user())]);
    }

    public function sales(FinancialReportRequest $request, SalesLineReportService $service): JsonResponse
    {
        return response()->json($service->report($request->validated()));
    }

    public function cash(FinancialReportRequest $request, CashPositionReportService $service): JsonResponse
    {
        return response()->json($service->report($request->validated()));
    }

    public function accounts(FinancialReportRequest $request, FinancialAccountReportService $service): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json($service->accounts($request->validated()));
    }

    public function accountMovements(FinancialAccount $financialAccount, FinancialReportRequest $request, FinancialAccountReportService $service): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json($service->movements($financialAccount, $request->validated()));
    }

    public function export(string $report, string $format, FinancialReportRequest $request): BinaryFileResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $filters = $request->validated();
        $map = null;
        if ($report === 'sales-lines') {
            $service = app(SalesLineReportService::class);
            $query = $service->rows($filters);
            $result = $service->report([...$filters, 'per_page' => 1]);
            $summary = [...$result['summary'], 'advertencias' => implode(' ', $result['warnings'])];
            $columns = ['document_number' => 'Documento', 'kind' => 'Tipo', 'occurred_at' => 'Fecha', 'sku' => 'SKU', 'product_name' => 'Producto', 'brand_name' => 'Marca', 'category_name' => 'Categoría', 'customer_name' => 'Cliente', 'seller_name' => 'Vendedor', 'quantity' => 'Cantidad', 'quantity_sold' => 'Vendido', 'quantity_returned' => 'Devuelto', 'gross_sales' => 'Venta bruta', 'discounts' => 'Descuentos', 'returns_amount' => 'Devoluciones', 'net_sales' => 'Venta neta', 'tax' => 'Impuestos', 'cost' => 'Costo', 'profit' => 'Utilidad bruta', 'margin' => 'Margen %', 'incomplete' => 'Rentabilidad incompleta'];
            $map = fn ($row) => $service->normalize($row);
        } elseif ($report === 'cash-position') {
            $result = app(CashPositionReportService::class)->report($filters, true);
            $query = $result['query'];
            $summary = $result['summary'];
            $columns = ['occurred_at' => 'Registro efectivo', 'business_datetime' => 'Fecha comercial', 'kind' => 'Tipo', 'description' => 'Descripción', 'reference' => 'Referencia', 'amount' => 'Importe', 'balance' => 'Saldo', 'user_name' => 'Responsable', 'transfer_id' => 'Transferencia'];
            $map = fn ($row) => app(CashPositionReportService::class)->normalize($row, $summary['opening_balance']);
        } elseif ($report === 'payment-methods') {
            $service = app(CashPaymentQueryService::class);
            $query = $service->query((int) $filters['warehouse_id'], [...$filters, 'scope' => 'sales'])->orderBy('movements.created_at')->orderBy('movements.id');
            $summary = collect($service->summary((int) $filters['warehouse_id'], [...$filters, 'scope' => 'sales']))->mapWithKeys(fn ($row) => [$row->method.' · cobros' => (float) $row->incoming, $row->method.' · devoluciones' => (float) $row->outgoing, $row->method.' · neto' => (float) $row->net])->all();
            $columns = ['document_number' => 'Documento', 'created_at' => 'Fecha de registro', 'method_name' => 'Método', 'amount' => 'Importe', 'account_name' => 'Cuenta', 'reference_number' => 'Referencia', 'user_name' => 'Responsable'];
            $map = fn ($row) => [...$service->normalizeRow($row), 'amount' => (float) $row->amount, 'method_name' => $row->method_name ?? $row->method, 'account_name' => $row->liquidity_bucket === 'bank' && ! $row->financial_account_id ? 'Pendiente de asignar cuenta' : $row->account_name];
        } else {
            abort(404);
        }

        $summary = [...$summary, 'fecha_desde' => $filters['date_from'], 'fecha_hasta' => $filters['date_to'], 'sucursal_id' => $filters['warehouse_id'] ?? null];

        return Excel::download(new MoneyReportExport($query, $columns, $summary, $map), $report.'.'.$format, $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX);
    }
}
