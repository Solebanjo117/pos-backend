<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileDuplicateCategories extends Command
{
    protected $signature = 'catalog:reconcile-categories {--apply : Aplicar la conciliación después de aprobar el reporte}';

    protected $description = 'Reporta y, opcionalmente, concilia categorías duplicadas normalizadas';

    public function handle(): int
    {
        $groups = Category::query()
            ->select(['parent_id', 'normalized_name', DB::raw('COUNT(*) AS duplicate_count'), DB::raw('MIN(id) AS canonical_id')])
            ->groupBy('parent_id', 'normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('parent_id')
            ->orderBy('normalized_name')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No se encontraron categorías duplicadas.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($groups as $group) {
            $ids = Category::query()->where('parent_id', $group->parent_id)->where('normalized_name', $group->normalized_name)->orderBy('id')->pluck('id');
            $duplicates = $ids->reject(fn ($id) => (int) $id === (int) $group->canonical_id)->values();
            $rows[] = [
                $group->normalized_name,
                $group->parent_id ?? 'raíz',
                $group->canonical_id,
                $duplicates->implode(','),
                DB::table('products')->whereIn('category_id', $duplicates)->count(),
                DB::table('product_extra_categories')->whereIn('category_id', $duplicates)->count(),
            ];

            if ($this->option('apply')) {
                DB::transaction(fn () => $this->merge((int) $group->canonical_id, $duplicates->map(fn ($id) => (int) $id)->all()));
            }
        }

        $this->table(['Nombre normalizado', 'Padre', 'Canónica', 'Duplicadas', 'Productos', 'Adicionales'], $rows);
        $this->line($this->option('apply') ? 'Conciliación aplicada.' : 'Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.');

        return self::SUCCESS;
    }

    private function merge(int $canonicalId, array $duplicateIds): void
    {
        DB::table('products')->whereIn('category_id', $duplicateIds)->update(['category_id' => $canonicalId]);
        DB::table('product_attributes')->whereIn('source_category_id', $duplicateIds)->update(['source_category_id' => $canonicalId]);
        DB::table('category_media')->whereIn('category_id', $duplicateIds)->update(['category_id' => $canonicalId]);

        foreach ($duplicateIds as $duplicateId) {
            DB::table('product_extra_categories')->where('category_id', $duplicateId)->orderBy('product_id')->get()->each(function ($row) use ($canonicalId): void {
                DB::table('product_extra_categories')->insertOrIgnore(['product_id' => $row->product_id, 'category_id' => $canonicalId]);
            });
            DB::table('product_extra_categories')->where('category_id', $duplicateId)->delete();

            DB::table('category_parents')->where('category_id', $duplicateId)->get()->each(function ($row) use ($canonicalId): void {
                if ((int) $row->parent_id !== $canonicalId) {
                    DB::table('category_parents')->insertOrIgnore(['category_id' => $canonicalId, 'parent_id' => $row->parent_id, 'priority' => $row->priority, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                }
            });
            DB::table('category_parents')->where('parent_id', $duplicateId)->get()->each(function ($row) use ($canonicalId): void {
                if ((int) $row->category_id !== $canonicalId) {
                    DB::table('category_parents')->insertOrIgnore(['category_id' => $row->category_id, 'parent_id' => $canonicalId, 'priority' => $row->priority, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                }
            });
            DB::table('category_parents')->where('category_id', $duplicateId)->orWhere('parent_id', $duplicateId)->delete();
            DB::table('categories')->where('parent_id', $duplicateId)->update(['parent_id' => $canonicalId]);
            Category::query()->whereKey($duplicateId)->delete();
        }
    }
}
