<?php

use App\Http\Controllers\Api\ProductAttributeController;
use App\Http\Controllers\Api\ProductCompatibilityController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Product Routes
Route::middleware([\App\Http\Middleware\EnforceWarehouseContext::class])->prefix('products')->group(function () {
    // CRUD Principal
    Route::get('/next-sku', [ProductController::class, 'nextSku'])->middleware('role:admin,manager,buyer');
    // R20: Check duplicate BEFORE /{product} routes to avoid route model binding conflict
    Route::get('/check-duplicate', [ProductController::class, 'checkDuplicate'])->middleware('role:admin,manager,buyer');
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store'])->middleware('role:admin,manager,buyer');
    Route::post('/{product}/duplicate', [ProductController::class, 'duplicate'])->middleware('role:admin,manager,buyer');
    Route::get('/{product}/profile', [ProductController::class, 'profile']);
    Route::get('/{product}', [ProductController::class, 'show']);
    Route::put('/{product}', [ProductController::class, 'update'])->middleware('role:admin,manager,buyer');
    Route::post('/{product}/preview-name', [ProductController::class, 'previewName'])->middleware('role:admin,manager,buyer');
    Route::post('/{product}/regenerate-name', [ProductController::class, 'regenerateName'])->middleware('role:admin,manager,buyer');
    Route::delete('/{product}', [ProductController::class, 'destroy'])->middleware('role:admin,manager,buyer');
    Route::post('/{id}/restore', [ProductController::class, 'restore'])->middleware('role:admin,manager,buyer');

    // --> Product Images (Multi-photo)
    Route::get('/{product}/images', [ProductController::class, 'getImages']);
    Route::post('/{product}/images', [ProductController::class, 'uploadImage'])->middleware('role:admin,manager,buyer');
    Route::delete('/{product}/images/{media}', [ProductController::class, 'deleteImage'])->middleware('role:admin,manager,buyer');

    // --> Product Attributes (Specifications) - Nested
    Route::get('/{product}/attributes', [ProductAttributeController::class, 'index']);

    // --> Vehicle Compatibility (Fitment) - Nested
    Route::get('/{product}/compatibility', [ProductCompatibilityController::class, 'index']);

    // Batch Management
    Route::get('/{product}/batches', [ProductController::class, 'getBatches']);

    // --> Product Variants (Brands)
    Route::get('/{product}/variants', [App\Http\Controllers\Api\ProductVariantController::class, 'index']);
    Route::post('/{product}/variants', [App\Http\Controllers\Api\ProductVariantController::class, 'store'])->middleware('role:admin,manager,buyer');
    Route::get('/{product}/variants/{variant}/inventory-history', [App\Http\Controllers\Api\ProductVariantController::class, 'inventoryHistory']);
    Route::get('/{product}/variants/{variant}/fifo-history', [App\Http\Controllers\Api\ProductVariantController::class, 'fifoHistory']);
    Route::put('/{product}/variants/{variant}', [App\Http\Controllers\Api\ProductVariantController::class, 'update'])->middleware('role:admin,manager,buyer');
    Route::delete('/{product}/variants/{variant}', [App\Http\Controllers\Api\ProductVariantController::class, 'destroy'])->middleware('role:admin,manager,buyer');

    // --> OEM References
    Route::get('/{product}/oem-references', [ProductController::class, 'oemReferences']);
    Route::post('/{product}/oem-references', [ProductController::class, 'addOemReference'])->middleware('role:admin,manager,buyer');
    Route::delete('/{product}/oem-references/{referenceId}', [ProductController::class, 'removeOemReference'])->middleware('role:admin,manager,buyer');

    // --> Alternatives (Interchange)
    Route::get('/{product}/alternatives', [ProductController::class, 'alternatives']);
    Route::post('/{product}/alternatives', [ProductController::class, 'addAlternative'])->middleware('role:admin,manager,buyer');
    Route::delete('/{product}/alternatives/{alternativeId}', [ProductController::class, 'removeAlternative'])->middleware('role:admin,manager,buyer');

    // --> VIN Linking
    Route::get('/{product}/vins', [ProductController::class, 'vins']);
    Route::post('/{product}/vins', [ProductController::class, 'addVin'])->middleware('role:admin,manager,buyer');
    Route::delete('/{product}/vins/{vinId}', [ProductController::class, 'removeVin'])->middleware('role:admin,manager,buyer');

    // --> Vehicle Compatibility (VIN-based lookup)
    Route::get('/{product}/vehicle-compatibility', [ProductController::class, 'vehicleCompatibility']);
});

// Standalone Routes for Attributes & Compatibility (RESTful resources)
// These allow creating/updating/deleting without deeply nested URLs if preferred,
// or can be used alongside nested ones.

// Attributes CRUD
Route::post('/product-attributes', [ProductAttributeController::class, 'store'])->middleware('role:admin,manager,buyer');
Route::put('/product-attributes/{id}', [ProductAttributeController::class, 'update'])->middleware('role:admin,manager,buyer');
Route::delete('/product-attributes/{id}', [ProductAttributeController::class, 'destroy'])->middleware('role:admin,manager,buyer');

// Compatibility CRUD
Route::post('/products/compatibility', [ProductCompatibilityController::class, 'store'])->middleware('role:admin,manager,buyer'); // Kept path for consistency with previous plan
Route::put('/products/compatibility/{id}', [ProductCompatibilityController::class, 'update'])->middleware('role:admin,manager,buyer');
Route::delete('/products/compatibility/{id}', [ProductCompatibilityController::class, 'destroy'])->middleware('role:admin,manager,buyer');

// Search by Vehicle
Route::get('/products/search/vehicle', [ProductCompatibilityController::class, 'search']);
