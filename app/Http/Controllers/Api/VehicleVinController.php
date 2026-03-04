<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleVin;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleVinController extends Controller
{
    /** List VINs for a given engine */
    public function index(int $engineId): JsonResponse
    {
        $vins = VehicleVin::where('engine_id', $engineId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $vins]);
    }

    /** Store a VIN for an engine */
    public function store(Request $request, int $engineId): JsonResponse
    {
        $validated = $request->validate([
            'vin'   => 'required|string|max:40|unique:vehicle_vins,vin',
            'notes' => 'nullable|string|max:255',
        ]);

        $vin = VehicleVin::create([
            'engine_id' => $engineId,
            'vin'       => strtoupper($validated['vin']),
            'notes'     => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $vin], 201);
    }

    /** Delete a VIN */
    public function destroy(int $engineId, int $vinId): JsonResponse
    {
        $vin = VehicleVin::where('engine_id', $engineId)->findOrFail($vinId);
        $vin->delete();

        return response()->json(['message' => 'VIN eliminado'], 200);
    }
}
