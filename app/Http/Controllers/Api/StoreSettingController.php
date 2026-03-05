<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreSettingController extends Controller
{
    /**
     * GET /api/v1/store-settings
     * Returns all store settings as a flat key→value map.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::where('group', 'store')->get();

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
        ];

        foreach ($allowed as $key) {
            if ($request->has($key)) {
                Setting::set($key, $request->input($key), 'string', 'store');
            }
        }

        return response()->json([
            'message' => 'Configuración guardada correctamente',
            'data'    => Setting::where('group', 'store')->get()->mapWithKeys(fn($s) => [$s->key => $s->getTypedValue()]),
        ]);
    }

    /**
     * POST /api/v1/store-settings/logo
     * Upload the store logo. Stores in storage/public/store/ and updates the URL setting.
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
}
