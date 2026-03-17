<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceWarehouseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $requested = $request->input('warehouse_id');
        if (is_array($requested)) {
            $requested = reset($requested);
        }
        $requested = filter_var($requested, FILTER_VALIDATE_INT) ?: null;

        $effective = null;

        if ($user->isAdmin()) {
            if ($requested && $user->hasWarehouseAccess($requested)) {
                $effective = $requested;
            } else {
                $effective = $user->getEffectiveWarehouseId();
            }
        } else {
            $effective = $user->getEffectiveWarehouseId();
            if (!$effective) {
                return response()->json([
                    'message' => 'Debe tener una sucursal asignada para realizar esta acción.',
                ], 409);
            }
        }

        if ($effective) {
            $request->query->set('warehouse_id', (string) $effective);
            $request->request->set('warehouse_id', $effective);
        }

        return $next($request);
    }
}
