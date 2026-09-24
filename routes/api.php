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
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ReportController;


// =====================================================
// AUTH
// =====================================================

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);

    Route::put('/profile', [
        AuthController::class,
        'updateProfile'
    ]);

    Route::post('/logout', [AuthController::class, 'logout']);

});


// =====================================================
// ADMIN ONLY
// =====================================================

Route::middleware([
    'auth:sanctum',
    'role:admin'
])->group(function () {

    // =================================================
    // CATEGORIES
    // =================================================

    Route::apiResource(
        'categories',
        CategoryController::class
    );


    // =================================================
    // UNITS
    // =================================================

    Route::apiResource(
        'units',
        UnitController::class
    );


    // =================================================
    // PRODUCTS - CREATE
    // =================================================

    Route::post(
        '/products',
        [ProductController::class, 'store']
    );


    // =================================================
    // PRODUCTS - UPDATE
    // =================================================

    Route::put(
        '/products/{product}',
        [ProductController::class, 'update']
    );


    // =================================================
    // PRODUCTS - DELETE
    // =================================================

    Route::delete(
        '/products/{product}',
        [ProductController::class, 'destroy']
    );


    // =================================================
    // PRODUCT UNITS - ADMIN CRUD
    // =================================================

    Route::post(
        '/products/{product}/product-units',
        [ProductUnitController::class, 'store']
    );

    Route::put(
        '/products/{product}/product-units/{productUnit}',
        [ProductUnitController::class, 'update']
    );

    Route::patch(
        '/products/{product}/product-units/{productUnit}',
        [ProductUnitController::class, 'update']
    );

    Route::delete(
        '/products/{product}/product-units/{productUnit}',
        [ProductUnitController::class, 'destroy']
    );


    // =================================================
    // STOCK MANAGEMENT
    // =================================================

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


    // =================================================
    // EMPLOYEES / PEGAWAI
    // =================================================

    Route::apiResource(
        'employees',
        UserController::class
    )->parameters([
        'employees' => 'user'
    ]);


    // =================================================
    // REPORTS
    // =================================================

    Route::get('/reports/sales', [
        ReportController::class,
        'sales'
    ]);

    Route::get('/reports/stock', [
        ReportController::class,
        'stock'
    ]);


    // =================================================
    // CONFIRM PAYMENT
    // =================================================

    Route::post(
        '/transactions/{transaction}/confirm-payment',
        [TransactionController::class, 'confirmPayment']
    );

});


// =====================================================
// ADMIN + KASIR
// =====================================================

Route::middleware([
    'auth:sanctum',
    'role:admin,kasir'
])->group(function () {

    // =================================================
    // PRODUCTS - READ
    // =================================================

    Route::get(
        '/products',
        [ProductController::class, 'index']
    );

    Route::get(
        '/products/{product}',
        [ProductController::class, 'show']
    );


    // =================================================
    // PRODUCT UNITS - READ
    // Admin dan Kasir dapat melihat unit produk
    // =================================================

    Route::get(
        '/products/{product}/product-units',
        [ProductUnitController::class, 'index']
    );

    Route::get(
        '/products/{product}/product-units/{productUnit}',
        [ProductUnitController::class, 'show']
    );


    // =================================================
    // CUSTOMERS
    // Admin dan Kasir dapat mengakses pelanggan
    // =================================================

    Route::apiResource(
        'customers',
        CustomerController::class
    );


    // =================================================
    // TRANSACTIONS
    // =================================================

    Route::apiResource(
        'transactions',
        TransactionController::class
    )->only([
        'index',
        'store',
        'show'
    ]);

    // =================================================
    // PAYMENT / BAYAR UTANG
    // =================================================

    Route::post(
        '/transactions/{transaction}/payments',
        [TransactionController::class, 'payDebt']
    );

    // =================================================
    // REPRINT TRANSACTION
    // =================================================

    Route::get(
        '/transactions/{transaction}/reprint',
        [
            TransactionController::class,
            'reprint'
        ]
    );


    // =================================================
    // PAYMENT HISTORIES
    // =================================================

    Route::get('/payment-histories', [
        TransactionController::class,
        'paymentHistory'
    ]);

});