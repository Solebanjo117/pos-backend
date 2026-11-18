<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los nombres de producto ya traen el carro ("TERMINAL CREM NIS SENTRA B17 12- M14x1.50RACK…").
 * Este comando lo lee y lo guarda como carro compatible del producto (decisión de Luis, 2026-11-18):
 * - Si el vehículo existe sin ambigüedad en el catálogo (marca + modelo y chasis), se vincula a él.
 * - Si no, se guarda como texto legible (scope "raw"), p. ej. "Nissan Sentra B17 2012+".
 * Solo toca productos que todavía no tienen carros. El primero queda como carro principal.
 */
class VehiclesFromProductNames extends Command
{
    protected $signature = 'catalog:vehicles-from-names
        {--apply : Guardar después de aprobar el reporte}
        {--limit=40 : Filas a mostrar en el reporte}';

    protected $description = 'Lee el carro escrito en el nombre de cada producto y lo guarda como carro compatible';

    /** Abreviaturas usadas en los nombres. */
    public const MAKES = [
        'TOY' => 'Toyota', 'NIS' => 'Nissan', 'MAZ' => 'Mazda', 'MIT' => 'Mitsubishi', 'MITS' => 'Mitsubishi',
        'HYU' => 'Hyundai', 'HYUN' => 'Hyundai', 'KIA' => 'Kia', 'CHEV' => 'Chevrolet', 'DAEW' => 'Daewoo',
        'DAIH' => 'Daihatsu', 'HON' => 'Honda', 'SUZ' => 'Suzuki', 'ISU' => 'Isuzu', 'FORD' => 'Ford',
        'SUB' => 'Subaru', 'VW' => 'Volkswagen',
    ];

    public function handle(): int
    {
        $products = Product::query()
            ->whereDoesntHave('vehicleCompatibility')
            ->orderBy('id')
            ->get(['id', 'name']);

        $plan = $products->map(fn (Product $product) => ['product' => $product, 'vehicles' => $this->parse($product->name)]);
        $found = $plan->filter(fn (array $row) => $row['vehicles'] !== []);

        $this->table(
            ['Producto', 'Carro(s) leído(s)', 'Cómo se guarda'],
            $found->take((int) $this->option('limit'))->map(fn (array $row) => [
                '#'.$row['product']->id.' '.Str::limit($row['product']->name, 55),
                collect($row['vehicles'])->pluck('text')->implode(' / '),
                collect($row['vehicles'])->map(fn ($v) => $v['model_id'] ? 'catálogo #'.$v['model_id'] : 'texto')->implode(' / '),
            ])->all(),
        );

        $vehicles = $found->flatMap(fn (array $row) => $row['vehicles']);
        $this->line(sprintf(
            'Productos sin carros: %d. Con carro leído: %d (%d carros: %d del catálogo, %d como texto). Sin carro en el nombre: %d.',
            $products->count(),
            $found->count(),
            $vehicles->count(),
            $vehicles->whereNotNull('model_id')->count(),
            $vehicles->whereNull('model_id')->count(),
            $products->count() - $found->count(),
        ));

        if (! $this->option('apply')) {
            $this->line('Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($found): void {
            foreach ($found as $row) {
                foreach ($row['vehicles'] as $index => $vehicle) {
                    ProductVehicleCompatibility::query()->create($vehicle['model_id']
                        ? [
                            'product_id' => $row['product']->id,
                            'scope_type' => 'model',
                            'make_id' => $vehicle['make_id'],
                            'model_id' => $vehicle['model_id'],
                            'all_engines' => true,
                            'all_chassis' => true,
                            'is_default' => $index === 0,
                            'notes' => 'Leído del nombre del producto',
                        ]
                        : [
                            'product_id' => $row['product']->id,
                            'scope_type' => 'raw',
                            'raw_vehicle_text' => $vehicle['text'],
                            'raw_vehicle_key' => self::rawKey($vehicle['text']),
                            'all_engines' => true,
                            'all_chassis' => true,
                            'is_default' => $index === 0,
                            'notes' => 'Leído del nombre del producto',
                        ]);
                }
            }

            activity()
                ->withProperties(['product_ids' => $found->pluck('product.id')->values()->all()])
                ->log('Carros compatibles leídos del nombre de los productos');
        });

        $this->line('Productos actualizados: '.$found->count().'.');

        return self::SUCCESS;
    }

    public static function rawKey(string $text): string
    {
        return mb_strtolower(trim(Str::ascii((string) preg_replace('/\s+/u', ' ', $text))));
    }

    /**
     * Carros escritos en un nombre. Cada uno: texto legible y, si existe sin ambigüedad, el modelo del catálogo.
     *
     * @return array<int, array{text: string, make_id: ?int, model_id: ?int}>
     */
    public function parse(string $name): array
    {
        $tokens = preg_split('/\s+/u', mb_strtoupper(trim($name))) ?: [];
        $start = collect($tokens)->search(fn ($token) => isset(self::MAKES[$token]));
        if ($start === false) {
            return [];
        }

        // El tramo del carro termina en la primera medida, lado, número de parte o código.
        $segment = [];
        foreach (array_slice($tokens, $start) as $token) {
            if ($this->isStopToken($token)) {
                break;
            }
            $segment[] = $token;
        }

        // Separar varios carros: una marca nueva después de un modelo empieza otro carro.
        $groups = [];
        $current = null;
        foreach ($segment as $token) {
            if (isset(self::MAKES[$token])) {
                if ($current !== null && $current['words'] === [] && $current['years'] === []) {
                    $current['makes'][] = self::MAKES[$token];

                    continue;
                }
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = ['makes' => [self::MAKES[$token]], 'words' => [], 'years' => []];

                continue;
            }
            if (preg_match('/^(\d{2})-$/', $token, $match)) {
                $current['years'][] = (int) $match[1];

                continue;
            }
            $current['words'][] = $token;
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        return collect($groups)
            ->filter(fn (array $group) => $group['words'] !== [])
            ->map(fn (array $group) => $this->vehicle($group))
            ->unique('text')
            ->values()
            ->all();
    }

    private function isStopToken(string $token): bool
    {
        return (bool) preg_match('/^[MH]\d+(\.\d+)?X/i', $token)       // medidas de rosca: M14x1.50RACK, H14x1.50RACK
            || in_array($token, ['LH', 'RH', 'DEL', 'TRAS', 'TOPRH', 'BOTRH', 'SUP', 'INF', 'IZQ', 'DER', 'USA', 'SINPS', 'CONPS', 'HEMBRA', 'MACHO'], true)
            || (bool) preg_match('/^\d{4,5}[A-Z]?-/', $token)            // números de parte: 45503-02070
            || (bool) preg_match('/^[A-Z]{1,4}-[A-Z]?\d/', $token)        // códigos: SR-3920, CRT-95, SR-N380
            || (bool) preg_match('/^[A-Z]{4}\d{2,}$/', $token)           // códigos: CRKH48
            || str_contains($token, 'LENGTH');
    }

    /**
     * @param  array{makes: array<int, string>, words: array<int, string>, years: array<int, int>}  $group
     * @return array{text: string, make_id: ?int, model_id: ?int}
     */
    private function vehicle(array $group): array
    {
        $years = array_map(fn (int $year) => $year >= 50 ? 1900 + $year : 2000 + $year, $group['years']);
        $yearText = match (count($years)) {
            0 => '',
            1 => ' '.$years[0].'+',
            default => ' '.min($years).'-'.max($years),
        };
        // El modelo y sus códigos se dejan como están escritos en el nombre (COROLLA E120 ZZFE).
        $text = implode('/', array_unique($group['makes'])).' '.implode(' ', $group['words']).$yearText;

        [$makeId, $modelId] = count(array_unique($group['makes'])) === 1
            ? $this->catalogModel($group['makes'][0], $group['words'])
            : [null, null];

        return ['text' => $text, 'make_id' => $makeId, 'model_id' => $modelId];
    }

    /**
     * Modelo del catálogo solo si hay exactamente uno: nombre igual a "MODELO CHASIS", o nombre MODELO con ese chasis.
     *
     * @param  array<int, string>  $words
     * @return array{0: ?int, 1: ?int}
     */
    private function catalogModel(string $makeName, array $words): array
    {
        // Solo "MODELO CHASIS" exacto: si el nombre trae más modelos o chasis, el texto completo no se pierde.
        $make = VehicleMake::query()->whereRaw('upper(name) = ?', [mb_strtoupper($makeName)])->first();
        if (! $make || count($words) !== 2) {
            return [null, null];
        }

        /** @var Collection<int, VehicleModel> $models */
        $models = VehicleModel::query()->where('make_id', $make->id)->get(['id', 'name', 'generation']);
        $model = $words[0];
        $chassis = $words[1];
        $matches = $models->filter(fn (VehicleModel $candidate) => mb_strtoupper(trim($candidate->name)) === $model.' '.$chassis
            || (mb_strtoupper(trim($candidate->name)) === $model && mb_strtoupper((string) $candidate->generation) === $chassis));

        return $matches->count() === 1 ? [$make->id, $matches->first()->id] : [null, null];
    }
}
