<?php

namespace App\Http\Controllers\Api;

use App\Helpers\TaxRateHelper;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\PricingFormulaService;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StoreSettingController extends Controller
{
    public function __construct(private readonly PricingFormulaService $formulaService) {}

    /**
     * GET /api/v1/store-settings
     * Returns all store settings as a flat key→value map.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::whereIn('group', $this->settingGroups())
            ->get(['*']);

        $map = $settings->mapWithKeys(fn ($s) => [$s->key => $s->getTypedValue()]);

        return response()->json(['data' => $map]);
    }

    /**
     * PUT /api/v1/store-settings
     * Bulk-update store settings. Accepts a flat key→value map.
     */
    public function update(Request $request): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'store_tax_rate');
        $this->normalizeUppercaseField($request, 'store_currency');

        $request->validate($this->validationRules());

        $allowed = [
            'store_name', 'store_tagline', 'store_owner_name',
            'store_phone', 'store_email', 'store_address',
            'store_tax_id', 'store_currency', 'store_ticket_footer',
            'store_tax_rate',
            'pricing_formula',
            'price_rounding_increment',
            'quote_expiration_days',
            'require_stock_for_quotes',
            'modules_work_orders_enabled',
            'sku_format',
            'regional_timezone',
            'regional_date_format',
            'regional_time_format',
            'regional_datetime_format',
            'regional_country',
            'regional_decimal_separator',
            'regional_settings_active',
            'accounting_lock_date',
        ];

        foreach ($allowed as $key) {
            if ($request->has($key)) {
                $group = match (true) {
                    in_array($key, ['pricing_formula', 'price_rounding_increment']) => 'pricing',
                    str_starts_with($key, 'modules_') => 'modules',
                    str_starts_with($key, 'quote_') || $key === 'require_stock_for_quotes' => 'quotes',
                    str_starts_with($key, 'regional_') => 'regional',
                    str_starts_with($key, 'accounting_') => 'accounting',
                    $key === 'sku_format' => 'sku',
                    default => 'store',
                };
                $type = match (true) {
                    in_array($key, ['quote_expiration_days', 'store_tax_rate', 'price_rounding_increment']) => 'number',
                    in_array($key, ['modules_work_orders_enabled', 'require_stock_for_quotes', 'regional_settings_active']) => 'boolean',
                    $key === 'sku_format' => 'json',
                    default => 'string',
                };
                // For JSON settings, serialize arrays before storing
                $valueToStore = ($type === 'json' && is_array($request->input($key)))
                    ? json_encode($request->input($key))
                    : $request->input($key);
                Setting::set($key, $valueToStore, $type, $group);
            }
        }

        return response()->json([
            'message' => 'Configuración guardada correctamente',
            'data' => Setting::whereIn('group', $this->settingGroups())->get(['*'])->mapWithKeys(fn ($s) => [$s->key => $s->getTypedValue()]),
        ]);
    }

    /**
     * POST /api/v1/store-settings/logo
     * Upload the store logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|max:2048', // 2 MB max
        ]);

        // Delete old logo if exists
        $oldUrl = Setting::get('store_logo_url');
        if ($oldUrl) {
            $oldPath = str_replace('/storage/', '', parse_url($oldUrl, PHP_URL_PATH));
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('store', 'public');
        $url = asset('storage/'.$path);

        Setting::set('store_logo_url', $url, 'string', 'store');

        return response()->json([
            'message' => 'Logo actualizado correctamente',
            'logo_url' => $url,
        ]);
    }

    /**
     * POST /api/v1/pricing-formula/preview
     * Preview the result of a pricing formula with a given cost.
     */
    public function previewFormula(Request $request): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'tax_rate');

        $request->validate([
            'formula' => 'required|string|max:255',
            'cost' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|integer|min:0|max:100',
        ]);

        $result = $this->formulaService->preview(
            $request->input('formula'),
            (float) $request->input('cost'),
            (float) TaxRateHelper::resolve($request->input('tax_rate'))
        );

        return response()->json($result);
    }

    /**
     * GET /api/v1/pricing-formula/presets
     * Returns the list of built-in formula presets.
     */
    public function formulaPresets(): JsonResponse
    {
        return response()->json([
            'data' => PricingFormulaService::presets(),
        ]);
    }

    private function normalizeIntegerPercentField(Request $request, string $field): void
    {
        if (! $request->has($field)) {
            return;
        }

        $value = $request->input($field);
        if (is_numeric($value)) {
            $request->merge([$field => (int) round((float) $value)]);
        }
    }

    private function normalizeUppercaseField(Request $request, string $field): void
    {
        if ($request->has($field) && is_string($request->input($field))) {
            $request->merge([$field => strtoupper(trim((string) $request->input($field)))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function validationRules(): array
    {
        return [
            'store_currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'regional_timezone' => ['sometimes', 'required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'regional_date_format' => ['sometimes', 'required', 'string', Rule::in(['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'])],
            'regional_time_format' => ['sometimes', 'required', 'string', Rule::in(['12h', '24h'])],
            'regional_datetime_format' => ['sometimes', 'required', 'string', Rule::in([
                'DD/MM/YYYY HH:mm',
                'DD/MM/YYYY hh:mm A',
                'MM/DD/YYYY HH:mm',
                'MM/DD/YYYY hh:mm A',
                'YYYY-MM-DD HH:mm',
                'YYYY-MM-DD hh:mm A',
            ])],
            'regional_country' => ['sometimes', 'required', 'string', 'max:80'],
            'regional_decimal_separator' => ['sometimes', 'required', 'string', Rule::in(['.', ','])],
            'regional_settings_active' => ['sometimes', 'boolean'],
            'accounting_lock_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function settingGroups(): array
    {
        return ['store', 'pricing', 'quotes', 'modules', 'sku', 'regional', 'accounting'];
    }
}
