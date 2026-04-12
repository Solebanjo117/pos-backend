<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PricingFormula;
use App\Services\PricingFormulaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingFormulaController extends Controller
{
    public function __construct(private readonly PricingFormulaService $formulaService) {}

    /**
     * GET /api/v1/pricing-formulas
     * List all active pricing formulas.
     */
    public function index(): JsonResponse
    {
        $formulas = PricingFormula::orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $formulas]);
    }

    /**
     * GET /api/v1/pricing-formulas/active
     * Only active formulas (for dropdowns).
     */
    public function active(): JsonResponse
    {
        $formulas = PricingFormula::active()->get();

        return response()->json(['data' => $formulas]);
    }

    /**
     * POST /api/v1/pricing-formulas
     * Create a custom pricing formula.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100|unique:pricing_formulas,name',
            'formula'     => 'required|string|max:500',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        // Validate formula syntax
        if (!$this->formulaService->validate($validated['formula'])) {
            return response()->json([
                'message' => 'La fórmula contiene caracteres inválidos o sintaxis incorrecta.',
                'errors'  => ['formula' => ['Fórmula inválida. Usa: costo, ITBM, *, +, -, /, ROUND(), ROUNDUP(), FLOOR(), CEIL()']],
            ], 422);
        }

        $formula = PricingFormula::create(array_merge($validated, ['is_preset' => false]));

        return response()->json([
            'message' => 'Fórmula creada correctamente.',
            'data'    => $formula,
        ], 201);
    }

    /**
     * GET /api/v1/pricing-formulas/{id}
     * Show a single formula.
     */
    public function show(int $id): JsonResponse
    {
        $formula = PricingFormula::findOrFail($id);

        return response()->json(['data' => $formula]);
    }

    /**
     * PUT /api/v1/pricing-formulas/{id}
     * Update a formula. Preset formulas can only update is_active and sort_order.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $formula = PricingFormula::findOrFail($id);

        if ($formula->is_preset) {
            // Presets: only allow toggling active status and reordering
            $validated = $request->validate([
                'is_active'  => 'boolean',
                'sort_order' => 'nullable|integer|min:0',
            ]);
        } else {
            $validated = $request->validate([
                'name'        => "required|string|max:100|unique:pricing_formulas,name,{$id}",
                'formula'     => 'required|string|max:500',
                'description' => 'nullable|string|max:500',
                'is_active'   => 'boolean',
                'sort_order'  => 'nullable|integer|min:0',
            ]);

            // Validate formula syntax
            if (!$this->formulaService->validate($validated['formula'])) {
                return response()->json([
                    'message' => 'La fórmula contiene caracteres inválidos.',
                    'errors'  => ['formula' => ['Fórmula inválida.']],
                ], 422);
            }
        }

        $formula->update($validated);

        return response()->json([
            'message' => 'Fórmula actualizada.',
            'data'    => $formula->fresh(),
        ]);
    }

    /**
     * DELETE /api/v1/pricing-formulas/{id}
     * Delete a custom formula. Presets cannot be deleted.
     */
    public function destroy(int $id): JsonResponse
    {
        $formula = PricingFormula::findOrFail($id);

        if ($formula->is_preset) {
            return response()->json([
                'message' => 'Las fórmulas predefinidas no se pueden eliminar. Puedes desactivarlas.',
            ], 403);
        }

        $formula->delete();

        return response()->json(['message' => 'Fórmula eliminada.']);
    }

    /**
     * POST /api/v1/pricing-formulas/preview
     * Preview the result of a formula string (inline, not from DB).
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'formula'  => 'required|string|max:500',
            'cost'     => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $result = $this->formulaService->preview(
            $request->input('formula'),
            (float) $request->input('cost'),
            (float) $request->input('tax_rate', 7.0)
        );

        return response()->json($result);
    }
}
