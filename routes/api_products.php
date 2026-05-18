<?php

use App\Http\Controllers\Api\ProductAttributeController;
use App\Http\Controllers\Api\ProductCompatibilityController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Product Routes
Route::middleware([\App\Http\Middleware\EnforceWarehouseContext::class])->prefix('products')->group(function () {
    // CRUD Principal
    Route::get('/next-sku', [ProductController::class, 'nextSku']);
    // R20: Check duplicate BEFORE /{product} routes to avoid route model binding conflict
    Route::get('/check-duplicate', [ProductController::class, 'checkDuplicate']);
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::post('/{product}/duplicate', [ProductController::class, 'duplicate']);
    Route::get('/{product}', [ProductController::class, 'show']);
    Route::put('/{product}', [ProductController::class, 'update']);
    Route::post('/{product}/preview-name', [ProductController::class, 'previewName']);
    Route::post('/{product}/regenerate-name', [ProductController::class, 'regenerateName']);
    Route::delete('/{product}', [ProductController::class, 'destroy']);
    Route::post('/{id}/restore', [ProductController::class, 'restore']);

    // --> Product Images (Multi-photo)
    Route::get('/{product}/images', [ProductController::class, 'getImages']);
    Route::post('/{product}/images', [ProductController::class, 'uploadImage']);
    Route::delete('/{product}/images/{media}', [ProductController::class, 'deleteImage']);

    // --> Product Attributes (Specifications) - Nested
    Route::get('/{product}/attributes', [ProductAttributeController::class, 'index']);

    // --> Vehicle Compatibility (Fitment) - Nested
    Route::get('/{product}/compatibility', [ProductCompatibilityController::class, 'index']);

    // Batch Management
    Route::get('/{product}/batches', [ProductController::class, 'getBatches']);

    // --> Product Variants (Brands)
    Route::get('/{product}/variants', [App\Http\Controllers\Api\ProductVariantController::class, 'index']);
    Route::post('/{product}/variants', [App\Http\Controllers\Api\ProductVariantController::class, 'store']);
    Route::get('/{product}/variants/{variant}/fifo-history', [App\Http\Controllers\Api\ProductVariantController::class, 'fifoHistory']);
    Route::put('/{product}/variants/{variant}', [App\Http\Controllers\Api\ProductVariantController::class, 'update']);
    Route::delete('/{product}/variants/{variant}', [App\Http\Controllers\Api\ProductVariantController::class, 'destroy']);

    // --> OEM References
    Route::get('/{product}/oem-references', [ProductController::class, 'oemReferences']);
    Route::post('/{product}/oem-references', [ProductController::class, 'addOemReference']);
    Route::delete('/{product}/oem-references/{referenceId}', [ProductController::class, 'removeOemReference']);

    // --> Alternatives (Interchange)
    Route::get('/{product}/alternatives', [ProductController::class, 'alternatives']);
    Route::post('/{product}/alternatives', [ProductController::class, 'addAlternative']);
    Route::delete('/{product}/alternatives/{alternativeId}', [ProductController::class, 'removeAlternative']);

    // --> VIN Linking
    Route::get('/{product}/vins', [ProductController::class, 'vins']);
    Route::post('/{product}/vins', [ProductController::class, 'addVin']);
    Route::delete('/{product}/vins/{vinId}', [ProductController::class, 'removeVin']);

    // --> Vehicle Compatibility (VIN-based lookup)
    Route::get('/{product}/vehicle-compatibility', [ProductController::class, 'vehicleCompatibility']);
});

// Standalone Routes for Attributes & Compatibility (RESTful resources)
// These allow creating/updating/deleting without deeply nested URLs if preferred,
// or can be used alongside nested ones.

// Attributes CRUD
Route::post('/product-attributes', [ProductAttributeController::class, 'store']);
Route::put('/product-attributes/{id}', [ProductAttributeController::class, 'update']);
Route::delete('/product-attributes/{id}', [ProductAttributeController::class, 'destroy']);

// Compatibility CRUD
Route::post('/products/compatibility', [ProductCompatibilityController::class, 'store']); // Kept path for consistency with previous plan
Route::put('/products/compatibility/{id}', [ProductCompatibilityController::class, 'update']);
Route::delete('/products/compatibility/{id}', [ProductCompatibilityController::class, 'destroy']);

// Search by Vehicle
Route::get('/products/search/vehicle', [ProductCompatibilityController::class, 'search']);
