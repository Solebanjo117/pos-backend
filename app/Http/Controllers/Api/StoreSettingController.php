<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\PricingFormulaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreSettingController extends Controller
{
    public function __construct(private readonly PricingFormulaService $formulaService) {}

    /**
     * GET /api/v1/store-settings
     * Returns all store settings as a flat key→value map.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::where('group', 'store')
            ->orWhere('group', 'pricing')
            ->orWhere('group', 'quotes')
            ->orWhere('group', 'modules')
            ->get(['*']);

        $map = $settings->mapWithKeys(fn($s) => [$s->key => $s->getTypedValue()]);

        return response()->json(['data' => $map]);
    }

    /**
     * PUT /api/v1/store-settings
     * Bulk-update store settings. Accepts a flat key→value map.
     */
    public function update(Request $request): JsonResponse
    {
        $allowed = [
            'store_name', 'store_tagline', 'store_owner_name',
            'store_phone', 'store_email', 'store_address',
            'store_tax_id', 'store_currency', 'store_ticket_footer',
            'store_tax_rate',
            'pricing_formula',
            'quote_expiration_days',
            'modules_work_orders_enabled',
        ];

        foreach ($allowed as $key) {
            if ($request->has($key)) {
                $group = match(true) {
                    $key === 'pricing_formula' => 'pricing',
                    str_starts_with($key, 'modules_') => 'modules',
                    str_starts_with($key, 'quote_') => 'quotes',
                    default => 'store',
                };
                $type = match(true) {
                    in_array($key, ['quote_expiration_days', 'store_tax_rate']) => 'number',
                    in_array($key, ['modules_work_orders_enabled']) => 'boolean',
                    default => 'string',
                };
                Setting::set($key, $request->input($key), $type, $group);
            }
        }

        return response()->json([
            'message' => 'Configuración guardada correctamente',
            'data'    => Setting::whereIn('group', ['store', 'pricing', 'quotes', 'modules'])->get(['*'])->mapWithKeys(fn($s) => [$s->key => $s->getTypedValue()]),
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
        $url  = asset('storage/' . $path);

        Setting::set('store_logo_url', $url, 'string', 'store');

        return response()->json([
            'message'   => 'Logo actualizado correctamente',
            'logo_url'  => $url,
        ]);
    }

    /**
     * POST /api/v1/pricing-formula/preview
     * Preview the result of a pricing formula with a given cost.
     */
    public function previewFormula(Request $request): JsonResponse
    {
        $request->validate([
            'formula'  => 'required|string|max:255',
            'cost'     => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $result = $this->formulaService->preview(
            $request->input('formula'),
            (float) $request->input('cost'),
            (float) $request->input('tax_rate', Setting::get('store_tax_rate', 7.0))
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
}
