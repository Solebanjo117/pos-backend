<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DataResetController extends Controller
{
    /**
     * POST /api/v1/data/reset
     */
    public function reset(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], 403);
        }

        $validated = $request->validate([
            'password' => 'required|string',
            'confirmation_text' => 'required|string|in:REINICIAR DATOS',
        ]);

        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Contraseña incorrecta.',
            ], 422);
        }

        try {
            $exitCode = Artisan::call('app:clear-data', [
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => trim(Artisan::output()) ?: 'No se pudo reiniciar la data.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Datos reiniciados correctamente. Se creo un backup previo.',
                'output' => trim(Artisan::output()),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reiniciar datos: ' . $e->getMessage(),
            ], 500);
        }
    }
}
