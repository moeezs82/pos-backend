<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\VendorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', function (Request $request) {
            return $request->user()->load('roles');
        });

        // Branches
        Route::get('/branches', [BranchController::class, 'index'])
            ->middleware('permission:view-branches');
        Route::post('/branches', [BranchController::class, 'store'])
            ->middleware('permission:manage-branches');
        Route::get('/branches/{id}', [BranchController::class, 'show'])
            ->middleware('permission:view-branches');
        Route::put('/branches/{id}', [BranchController::class, 'update'])
            ->middleware('permission:manage-branches');
        Route::delete('/branches/{id}', [BranchController::class, 'destroy'])
            ->middleware('permission:manage-branches');


        // Categories
        Route::get('/categories', [CategoryController::class, 'index'])
            ->middleware('permission:view-categories');
        Route::post('/categories', [CategoryController::class, 'store'])
            ->middleware('permission:manage-categories');
        Route::get('/categories/{id}', [CategoryController::class, 'show'])
            ->middleware('permission:view-categories');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])
            ->middleware('permission:manage-categories');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])
            ->middleware('permission:manage-categories');
        // Brands
        Route::get('/brands', [BrandController::class, 'index'])
            ->middleware('permission:view-brands');
        Route::post('/brands', [BrandController::class, 'store'])
            ->middleware('permission:manage-brands');
        Route::get('/brands/{id}', [BrandController::class, 'show'])
            ->middleware('permission:view-brands');
        Route::put('/brands/{id}', [BrandController::class, 'update'])
            ->middleware('permission:manage-brands');
        Route::delete('/brands/{id}', [BrandController::class, 'destroy'])
            ->middleware('permission:manage-brands');

        // Stock
        Route::get('/stocks', [StockController::class, 'index'])
            ->middleware('permission:view-stock');
        Route::post('/stocks/adjust', [StockController::class, 'adjust'])
            ->middleware('permission:adjust-stock');
        Route::post('/stocks/transfer', [StockController::class, 'transfer'])
            ->middleware('permission:adjust-stock');

        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->middleware('permission:view-products');
            Route::post('/', [ProductController::class, 'store'])->middleware('permission:manage-products');
            Route::get('/{id}', [ProductController::class, 'show'])->middleware('permission:view-products');
            Route::get('/by-barcode/{code}', [ProductController::class, 'findByBarcode'])->middleware('permission:view-products');
            Route::put('/{id}', [ProductController::class, 'update'])->middleware('permission:manage-products');
            Route::delete('/{id}', [ProductController::class, 'destroy'])->middleware('permission:manage-products');
        });


        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])->middleware('permission:view-customers');
            Route::post('/', [CustomerController::class, 'store'])->middleware('permission:view-customers');
            Route::get('/{customer}', [CustomerController::class, 'show'])->middleware('permission:view-customers');
            Route::put('/{customer}', [CustomerController::class, 'update'])->middleware('permission:view-customers');
            Route::delete('/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:view-customers');
        });
        Route::prefix('vendors')->group(function () {
            Route::get('/', [VendorController::class, 'index'])->middleware('permission:view-vendors');
            Route::post('/', [VendorController::class, 'store'])->middleware('permission:manage-vendors');
            Route::get('/{vendor}', [VendorController::class, 'show'])->middleware('permission:view-vendors');
            Route::put('/{vendor}', [VendorController::class, 'update'])->middleware('permission:manage-vendors');
            Route::delete('/{vendor}', [VendorController::class, 'destroy'])->middleware('permission:manage-vendors');
        });

        Route::prefix('sales')->group(function () {
            // --- Returns ---
            Route::prefix('returns')->middleware('permission:manage-sales')->group(function () {
                Route::get('/', [SaleReturnController::class, 'index']);
                Route::get('/{id}', [SaleReturnController::class, 'show']);
                Route::post('/', [SaleReturnController::class, 'store']);
                Route::post('/{id}/approve', [SaleReturnController::class, 'approve']);
            });


            Route::get('/', [SaleController::class, 'index'])->middleware('permission:view-sales');
            Route::post('/', [SaleController::class, 'store'])->middleware('permission:manage-sales');
            Route::get('/{id}', [SaleController::class, 'show'])->middleware('permission:view-sales');
            Route::post('/{sale}/payments', [PaymentController::class, 'store'])->middleware('permission:manage-sales');
        });

        Route::prefix('purchases')->group(function () {
            Route::get('/',                [PurchaseController::class, 'index'])->middleware('permission:view-purchases');
            Route::get('/{purchase}',      [PurchaseController::class, 'show'])->middleware('permission:view-purchases');
            Route::post('/',               [PurchaseController::class, 'store'])->middleware('permission:manage-purchases');
            Route::post('/{purchase}/receive',  [PurchaseController::class, 'receive'])->middleware('permission:manage-purchases');
            Route::post('/{purchase}/payments', [PurchaseController::class, 'addPayment'])->middleware('permission:manage-purchases');
            Route::post('/{purchase}/cancel',   [PurchaseController::class, 'cancel'])->middleware('permission:manage-purchases');
        });
    });
});
