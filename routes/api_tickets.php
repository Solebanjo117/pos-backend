<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TicketController;

/*
|--------------------------------------------------------------------------
| Ticket / Receipt Routes
|--------------------------------------------------------------------------
*/

Route::get('/sales-orders/{id}/ticket', [TicketController::class, 'ticket']);
Route::get('/sales-orders/{id}/note',   [TicketController::class, 'note']);
Route::get('/sales-orders/{id}/proforma', [TicketController::class, 'proforma']);
