<?php

namespace App\Exceptions;

use App\Http\Resources\ProductResource;
use App\Models\ProductCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductCodeConflictException extends RuntimeException
{
    public function __construct(public readonly ProductCode $conflictingCode)
    {
        parent::__construct('El código ya está registrado en otro producto o variante.');
    }

    public function render(Request $request): JsonResponse
    {
        $this->conflictingCode->loadMissing(['product.variants.codes', 'product.codes', 'variant.codes']);

        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PRODUCT_CODE_CONFLICT',
            'data' => [
                'matched_code' => $this->conflictingCode->code,
                'normalized_code' => $this->conflictingCode->normalized_code,
                'variant_id' => $this->conflictingCode->variant_id,
                'product' => (new ProductResource($this->conflictingCode->product))->resolve($request),
                'url' => '/products/'.$this->conflictingCode->product_id.'/edit',
            ],
        ], 409);
    }
}
