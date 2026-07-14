<?php

use App\Http\Controllers\Api\SalesCreditNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'role:admin,manager,cashier'])->group(function (): void {
    Route::get('/sales-credit-notes', [SalesCreditNoteController::class, 'index']);
    Route::get('/sales-credit-notes/{salesCreditNote}', [SalesCreditNoteController::class, 'show']);
});
