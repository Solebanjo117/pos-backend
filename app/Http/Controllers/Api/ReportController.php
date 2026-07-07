<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialPositionReportRequest;
use App\Http\Requests\IncomeStatementReportRequest;
use App\Http\Requests\LedgerTrialBalanceReportRequest;
use App\Http\Requests\SalesByClientReportRequest;
use App\Http\Requests\StoreFinancialAdjustmentRequest;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\AccountingReportService;
use App\Services\FinancialDrilldownService;
use App\Services\FinancialSnapshotService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    protected ReportService $reportService;

    protected FinancialSnapshotService $financialSnapshotService;

    protected FinancialDrilldownService $financialDrilldownService;

    protected AccountingReportService $accountingReportService;

    public function __construct(
        ReportService $reportService,
        FinancialSnapshotService $financialSnapshotService,
        FinancialDrilldownService $financialDrilldownService,
        AccountingReportService $accountingReportService,
    )
    {
        $this->reportService = $reportService;
        $this->financialSnapshotService = $financialSnapshotService;
        $this->financialDrilldownService = $financialDrilldownService;
        $this->accountingReportService = $accountingReportService;
    }

    /**
     * Get stock levels report
     */
    public function stockLevels(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'sort_by' => 'nullable|in:sku,name,brand,warehouse,current_stock,reorder_point,target_level,status,current_cost,stock_value',
            'sort_direction' => 'nullable|in:asc,desc',
        ]);

        $filters = $request->only(['warehouse_id', 'brand_id', 'category_id', 'status', 'page', 'sort_by', 'sort_direction']);

        $report = $this->reportService->generateStockLevelsReport($filters);

        return response()->json($report);
    }

    /**
     * Get movement history report
     */
    public function movementHistory(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'product_id' => 'nullable|exists:products,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'user_id' => 'nullable|exists:users,id',
            'movement_type' => 'nullable|in:in,out,transfer',
            'group_by' => 'nullable|in:day,week,month',
            'page' => 'nullable|integer|min:1',
        ]);

        $filters = $request->only([
            'date_from',
            'date_to',
            'product_id',
            'warehouse_id',
            'user_id',
            'movement_type',
            'group_by',
            'page',
        ]);

        $report = $this->reportService->generateMovementHistoryReport($filters);

        return response()->json($report);
    }

    /**
     * Export stock levels report
     */
    public function exportStockLevels(Request $request, string $format): Response
    {
        if (! in_array($format, ['pdf', 'excel'])) {
            return response()->json(['error' => 'Invalid format'], 400);
        }

        $filters = array_merge(
            $request->only(['warehouse_id', 'brand_id', 'category_id', 'status', 'sort_by', 'sort_direction']),
            ['export_all' => true]
        );
        $report = $this->reportService->generateStockLevelsReport($filters);

        if ($format === 'pdf') {
            $pdfService = app(\App\Services\PdfExportService::class);
            $pdf = $pdfService->exportStockLevels($report);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="stock-levels-report.pdf"',
            ]);
        }

        // Excel export
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\StockLevelsExport($report),
            'stock-levels-report.xlsx'
        );
    }

    public function exportInventoryItems(Request $request, string $format): Response
    {
        if (! in_array($format, ['csv', 'excel', 'xlsx'], true)) {
            return response()->json(['error' => 'Invalid format'], 400);
        }

        $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'brand_id' => 'nullable',
            'category_id' => 'nullable',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'has_stock' => 'nullable|boolean',
            'in_stock' => 'nullable|boolean',
            'archived' => 'nullable|in:true,false,all',
            'template' => 'nullable|boolean',
        ]);

        $rows = $this->reportService->generateInventoryItemsExportRows($request->only([
            'warehouse_id',
            'brand_id',
            'category_id',
            'status',
            'search',
            'has_stock',
            'in_stock',
            'archived',
        ]));
        $template = $request->boolean('template');
        $extension = $format === 'csv' ? 'csv' : 'xlsx';
        $filename = ($template ? 'inventory-items-template' : 'inventory-items-export').'.'.$extension;
        $writerType = $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : null;

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\InventoryItemsExport($rows, $template),
            $filename,
            $writerType
        );
    }

    /**
     * Export movement history report
     */
    public function exportMovementHistory(Request $request, string $format): Response
    {
        if (! in_array($format, ['pdf', 'excel'])) {
            return response()->json(['error' => 'Invalid format'], 400);
        }

        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $filters = $request->only([
            'date_from',
            'date_to',
            'product_id',
            'warehouse_id',
            'user_id',
            'movement_type',
            'group_by',
        ]);

        $report = $this->reportService->generateMovementHistoryReport($filters);

        if ($format === 'pdf') {
            $pdfService = app(\App\Services\PdfExportService::class);
            $pdf = $pdfService->exportMovementHistory($report);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="movement-history-report.pdf"',
            ]);
        }

        // Excel export
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\MovementHistoryExport($report),
            'movement-history-report.xlsx'
        );
    }

    /**
     * Get financial report
     */
    public function financial(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);
        $report = $this->reportService->generateFinancialReport($filters);

        return response()->json($report);
    }

    public function incomeStatement(IncomeStatementReportRequest $request): JsonResponse
    {
        $report = $this->reportService->generateIncomeStatementReport($request->validated());

        return response()->json($report);
    }

    public function financialPosition(FinancialPositionReportRequest $request): JsonResponse
    {
        $report = $this->reportService->generateFinancialPositionReport($request->validated());

        return response()->json($report);
    }

    public function ledgerTrialBalance(LedgerTrialBalanceReportRequest $request): JsonResponse
    {
        $report = $this->reportService->generateLedgerTrialBalance($request->validated());

        return response()->json($report);
    }

    public function accountingJournal(Request $request): JsonResponse
    {
        $validated = $this->validateAccountingFilters($request);

        return response()->json($this->accountingReportService->journal($validated));
    }

    public function accountingEntry(JournalEntry $entry): JsonResponse
    {
        return response()->json($this->accountingReportService->entry($entry));
    }

    public function accountingAccounts(Request $request): JsonResponse
    {
        $validated = $this->validateAccountingFilters($request);

        return response()->json($this->accountingReportService->accounts($validated));
    }

    public function accountingAccountLedger(Request $request, LedgerAccount $account): JsonResponse
    {
        $validated = $this->validateAccountingFilters($request);

        return response()->json($this->accountingReportService->accountLedger($account, $validated));
    }

    public function accountingTAccount(Request $request, LedgerAccount $account): JsonResponse
    {
        $validated = $this->validateAccountingFilters($request);

        return response()->json($this->accountingReportService->tAccount($account, $validated));
    }

    public function accountingTrialBalance(Request $request): JsonResponse
    {
        $validated = $this->validateAccountingFilters($request);

        return response()->json($this->accountingReportService->trialBalance($validated));
    }

    public function customerReferences(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id', 'customer_id']);
        $report = $this->reportService->generateCustomerReferenceReport($filters);

        return response()->json($report);
    }

    public function salesByClient(SalesByClientReportRequest $request): JsonResponse
    {
        $report = $this->reportService->generateSalesByClientReport($request->validated());

        return response()->json($report);
    }

    public function exportSalesByClient(SalesByClientReportRequest $request, string $format): Response
    {
        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json(['error' => 'Invalid format'], 400);
        }

        $report = $this->reportService->generateSalesByClientReport(array_merge(
            $request->validated(),
            ['page' => 1, 'per_page' => 100000]
        ));

        if ($format === 'pdf') {
            $pdfService = app(\App\Services\PdfExportService::class);
            $pdf = $pdfService->exportSalesByClient($report);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="sales-by-client-report.pdf"',
            ]);
        }

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SalesByClientExport($report),
            'sales-by-client-report.xlsx'
        );
    }

    /**
     * Get brand profitability report
     */
    public function brandProfitability(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);
        $report = $this->reportService->generateBrandProfitability($filters);

        return response()->json($report);
    }

    /**
     * Get category profitability report
     */
    public function categoryProfitability(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);
        $report = $this->reportService->generateCategoryProfitability($filters);

        return response()->json($report);
    }

    /**
     * Get Z-Report
     */
    public function zReport(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $date = $request->input('date', now()->toDateString());
        $warehouseId = $request->input('warehouse_id');
        $userId = $request->input('user_id');
        $report = $this->reportService->generateZReport($date, $warehouseId, $userId);

        return response()->json($report);
    }

    /**
     * Export Z-Report to PDF
     */
    public function exportZReport(Request $request): Response
    {
        $request->validate([
            'date' => 'nullable|date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $date = $request->input('date', now()->toDateString());
        $warehouseId = $request->input('warehouse_id');
        $userId = $request->input('user_id');
        $report = $this->reportService->generateZReport($date, $warehouseId, $userId);

        // Ideally handled by PdfExportService, but for now we format a simple response or use a view if available.
        if (app()->bound(\App\Services\PdfExportService::class)) {
            $pdfService = app(\App\Services\PdfExportService::class);
            if (method_exists($pdfService, 'exportZReport')) {
                $pdf = $pdfService->exportZReport($report);

                return response($pdf, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="z-report-'.$date.'.pdf"',
                ]);
            }
        }

        // Fallback for PDF generation if method not available
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML('<h1>Cierre de Caja (Z-Report) - '.$date.'</h1><pre>'.print_r($report, true).'</pre>');

        return $pdf->download('z-report-'.$date.'.pdf');
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'rebuild' => 'nullable|boolean',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);

        return response()->json(
            $this->financialSnapshotService->rangeSummary($filters, $request->boolean('rebuild')),
        );
    }

    public function financialSnapshots(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'rebuild' => 'nullable|boolean',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);

        return response()->json(
            $this->financialSnapshotService->rangeSummary($filters, $request->boolean('rebuild')),
        );
    }

    public function financialWeekly(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'week_start' => 'nullable|date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'rebuild' => 'nullable|boolean',
        ]);

        return response()->json(
            $this->financialSnapshotService->weeklySummary($request->only(['date', 'week_start', 'warehouse_id']), $request->boolean('rebuild')),
        );
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);

        return response()->json(
            $this->financialSnapshotService->balanceSheet($filters),
        );
    }

    public function financialDrilldown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => 'required|string|in:sales,sales_total,payments_received,sales_collected,purchases,purchase,supplier_payments,purchase_paid,expenses,operational_expenses,cash,bank,inventory,receivable,accounts_receivable,credit_receivable,sales_pending,payable,accounts_payable,credit_payable,purchase_pending,cogs,profit,gross_profit,net_profit,assets,liabilities,equity,investment,investment_in,investment_out,adjustments',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'expense_category' => 'nullable|string|max:100',
            'expense_group' => 'nullable|string|in:rent,salary,utilities,internet,other',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->financialDrilldownService->handle($validated));
    }

    public function financialAdvancedStatus(): JsonResponse
    {
        $journalEntries = DB::table('journal_entries')->count();
        $journalLines = DB::table('journal_lines')->count();
        $ledgerEntries = DB::table('account_ledger_entries')->count();
        $hasJournal = $journalEntries > 0 && $journalLines > 0;

        return response()->json([
            'ledger' => [
                'journal_entries' => $journalEntries,
                'journal_lines' => $journalLines,
                'account_ledger_entries' => $ledgerEntries,
                'is_available' => $hasJournal,
            ],
            'reports' => [
                ['key' => 'operational_income_statement', 'label' => 'Estado de resultados operativo', 'status' => 'available', 'source' => 'Ventas, COGS y gastos operativos'],
                ['key' => 'operational_balance', 'label' => 'Balance operativo', 'status' => 'available', 'source' => 'Snapshots, inventario, CxC y CxP'],
                ['key' => 'receivables', 'label' => 'Cuentas por cobrar detalladas', 'status' => 'available', 'source' => 'Ventas menos pagos reales'],
                ['key' => 'payables', 'label' => 'Cuentas por pagar detalladas', 'status' => 'available', 'source' => 'Compras menos pagos reales'],
                ['key' => 'cash_flow', 'label' => 'Flujo de efectivo operativo', 'status' => 'partial', 'source' => 'Pagos operativos; no es ledger formal'],
                ['key' => 'general_journal', 'label' => 'Libro Diario formal', 'status' => $hasJournal ? 'available' : 'pending', 'source' => $hasJournal ? 'Journal entries' : 'Pendiente de alimentar journal_entries y journal_lines'],
                ['key' => 'general_ledger', 'label' => 'Mayor General formal', 'status' => $hasJournal ? 'available' : 'pending', 'source' => $hasJournal ? 'Journal lines' : 'Sin asientos contables disponibles'],
                ['key' => 't_accounts', 'label' => 'Cuentas T formales', 'status' => $hasJournal ? 'available' : 'pending', 'source' => $hasJournal ? 'Journal lines' : 'Disponible cuando existan asientos contables'],
                ['key' => 'trial_balance', 'label' => 'Trial Balance real', 'status' => $hasJournal ? 'available' : 'pending', 'source' => $hasJournal ? 'Journal lines' : 'Requiere journal_entries y journal_lines'],
                ['key' => 'formal_balance', 'label' => 'Balance contable formal', 'status' => $hasJournal ? 'available' : 'pending', 'source' => $hasJournal ? 'Journal lines' : 'No se genera con datos operativos para evitar contabilidad falsa'],
            ],
        ]);
    }

    public function storeCapitalMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'movement_type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'movement_date' => 'required|date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->financialSnapshotService->storeCapitalMovement(
                $validated,
                $request->user()?->id,
            ),
        ], 201);
    }

    public function capitalMovements(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->financialSnapshotService->listCapitalMovements(
                $request->only(['date_from', 'date_to', 'warehouse_id']),
            ),
        ]);
    }

    public function storeAdjustment(StoreFinancialAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $adjustment = $this->reportService->storeFinancialAdjustment(
            $validated,
            $request->user()?->id,
        );
        $this->financialSnapshotService->rebuildRange(
            $validated['adjustment_date'],
            now()->toDateString(),
            $validated['warehouse_id'] ?? null,
            true,
        );

        return response()->json([
            'success' => true,
            'data' => $adjustment,
        ], 201);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'warehouse_id']);

        return response()->json([
            'success' => true,
            'data' => $this->reportService->listFinancialAdjustments($filters),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAccountingFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
