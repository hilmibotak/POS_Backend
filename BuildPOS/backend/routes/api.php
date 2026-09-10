<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductUnitController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\StockMovementController;

// =====================================================
// AUTH
// =====================================================

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);

    Route::post('/logout', [AuthController::class, 'logout']);

});


// =====================================================
// ADMIN ONLY
// =====================================================

Route::middleware([
    'auth:sanctum',
    'role:admin'
])->group(function () {

    // Categories
    Route::apiResource('categories', CategoryController::class);

    // Units
    Route::apiResource('units', UnitController::class);

    // Products - Create
    Route::post(
        '/products',
        [ProductController::class, 'store']
    );

    // Products - Update
    Route::put(
        '/products/{product}',
        [ProductController::class, 'update']
    );

    // Products - Delete
    Route::delete(
        '/products/{product}',
        [ProductController::class, 'destroy']
    );

    // Product Units
    Route::apiResource(
        'products.product-units',
        ProductUnitController::class
    );

    // Stock Management
    Route::get(
        '/stock-movements',
        [StockMovementController::class, 'index']
    );

    Route::post(
        '/stock-movements/in',
        [StockMovementController::class, 'stockIn']
    );

    Route::post(
        '/stock-movements/adjustment',
        [StockMovementController::class, 'adjustment']
    );

    Route::get(
        '/stock-movements/{stockMovement}',
        [StockMovementController::class, 'show']
    );

});


// =====================================================
// ADMIN + KASIR
// =====================================================

Route::middleware([
    'auth:sanctum',
    'role:admin,kasir'
])->group(function () {

    // Products - Read
    Route::get(
        '/products',
        [ProductController::class, 'index']
    );

    Route::get(
        '/products/{product}',
        [ProductController::class, 'show']
    );

   Route::apiResource('transactions', TransactionController::class)
        ->only(['index','store','show']);

    Route::get('/transactions/{transaction}/reprint', [
        TransactionController::class,
        'reprint'
    ]);

});