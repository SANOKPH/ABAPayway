<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Payment lifecycle:
|   POST /api/payments          create a PENDING transaction + PayWay form
|   GET  /api/payments/return   browser return (never marks success)
|   GET  /api/payments/cancel   browser cancel
|   POST /api/payments/callback server-to-server authoritative callback
|   GET  /api/payments/{id}     minimal status lookup for result pages
|
*/

Route::get('/payments/return', [PaymentController::class, 'return']);
Route::get('/payments/cancel', [PaymentController::class, 'cancel']);
Route::post('/payments/callback', [PaymentController::class, 'callback']);

Route::post('/payments', [PaymentController::class, 'store']);
Route::get('/payments/{tran_id}', [PaymentController::class, 'show']);
