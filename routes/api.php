<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StockController;
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
            Route::put('/{id}', [ProductController::class, 'update'])->middleware('permission:manage-products');
            Route::delete('/{id}', [ProductController::class, 'destroy'])->middleware('permission:manage-products');
        });
    });
});
