<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Support\RegionalDateRange;

class FinancialAccountReportService
{
    public function accounts(array $filters): array
    {
        $query = FinancialAccount::query()->with('ledgerAccount:id,code,name')
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhereRaw('LOWER(institution_name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhere('masked_number', 'like', '%'.$search.'%')));
        $totals = (clone $query)->select('currency')->selectRaw('SUM(current_balance) as balance')->groupBy('currency')->get();
        $paginator = $query->orderBy($filters['sort_by'] ?? 'name', $filters['sort_direction'] ?? 'asc')->orderBy('id')->paginate($filters['per_page'] ?? 25);

        return ['success' => true, ...$paginator->toArray(), 'totals_by_currency' => $totals];
    }

    public function movements(FinancialAccount $account, array $filters): array
    {
        [$from, $to] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);
        $query = $account->movements()->with('creator:id,name')->whereRaw('COALESCE(business_datetime, created_at) BETWEEN ? AND ?', [$from, $to])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%')));
        $totals = (clone $query)->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) as incoming, SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) as outgoing")->first();
        $totals = ['incoming' => round((float) $totals->incoming, 2), 'outgoing' => round((float) $totals->outgoing, 2)];
        $page = $query->orderByRaw('COALESCE(business_datetime, created_at) DESC')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(function ($movement) {
            $movement->document_url = $movement->source_type === 'financial_transfer' ? '/finance/transfers/'.$movement->source_id : null;

            return $movement;
        });

        return [...$page->toArray(), 'account' => $account, 'summary' => $totals];
    }
}
