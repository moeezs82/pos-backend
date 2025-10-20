<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CashBookController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DayBookController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseClaimController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleItemController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\UserController;
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

        // users
        Route::prefix('users')->middleware('permission:view-users')->group(function () {
            Route::get('/',            [UserController::class, 'index']);
            Route::post('/',            [UserController::class, 'store'])->middleware('permission:manage-users');
            Route::get('/{user}',     [UserController::class, 'show']);
            Route::put('/{user}',     [UserController::class, 'update'])->middleware('permission:manage-users');
            Route::delete('/{user}',     [UserController::class, 'destroy'])->middleware('permission:manage-users');

            // Assignments
            Route::post('/{user}/roles',        [UserController::class, 'syncRoles'])->middleware('permission:manage-users');
            // Route::post('/{user}/permissions',  [UserController::class, 'syncPermissions'])->middleware('permission:manage-users');
        });

        // Roles
        Route::prefix('roles')->middleware('permission:view-roles')->group(function () {
            Route::get('/',           [RoleController::class, 'index']);
            Route::post('/',           [RoleController::class, 'store'])->middleware('permission:manage-roles');
            Route::get('/permissions',    [RoleController::class, 'availablePermissions']);
            Route::get('/{role}',    [RoleController::class, 'show']);
            Route::put('/{role}',    [RoleController::class, 'update'])->middleware('permission:manage-roles');
            Route::delete('/{role}',    [RoleController::class, 'destroy'])->middleware('permission:manage-roles');
            Route::post('/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:manage-roles');
        });

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
            Route::get('/by-barcode/{code}/{vendor_id?}', [ProductController::class, 'findByBarcode'])->middleware('permission:view-products');
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

        Route::prefix('accounts')->middleware('permission:manage-accounts')->group(function () {
            Route::get('/types', [AccountController::class, 'getTypes']);
            Route::get('/',      [AccountController::class, 'index']);

            Route::post('/',        [AccountController::class, 'store']);
            Route::get('/{id}',   [AccountController::class, 'show']);
            Route::put('/{id}',   [AccountController::class, 'update']);
            Route::put('/{id}/activate',   [AccountController::class, 'activate']);
            Route::put('/{id}/deactivate', [AccountController::class, 'deactivate']);
        });

        Route::prefix('sales')->group(function () {
            // --- Returns ---
            Route::prefix('returns')->middleware('permission:manage-sales')->group(function () {
                Route::get('/', [SaleReturnController::class, 'index']);
                Route::get('/{id}', [SaleReturnController::class, 'show']);
                Route::post('/', [SaleReturnController::class, 'store']);
                Route::post('/{id}/approve', [SaleReturnController::class, 'approve']);
                Route::post('/{id}/refund', [SaleReturnController::class, 'refund']);
            });


            Route::get('/', [SaleController::class, 'index'])->middleware('permission:view-sales');
            Route::post('/', [SaleController::class, 'store'])->middleware('permission:create-sales');
            Route::get('/{id}', [SaleController::class, 'show'])->middleware('permission:view-sales');
            Route::put('/{id}', [SaleController::class, 'update'])->middleware('permission:manage-sales');
            Route::prefix('{sale}')->middleware('permission:manage-sales')->group(function () {
                Route::post('payments', [PaymentController::class, 'store']);
                Route::put('payments/{payment}', [PaymentController::class, 'update']);
                Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);

                // Items
                Route::post('items', [SaleItemController::class, 'store']);               // ADD item
                Route::put('items/{item}', [SaleItemController::class, 'update']);        // EDIT item
                Route::delete('items/{item}', [SaleItemController::class, 'destroy']);    // DELETE item
            });
        });

        Route::prefix('purchases')->group(function () {
            Route::get('/',                    [PurchaseController::class, 'index'])->middleware('permission:view-purchases');
            Route::get('/{purchase}',          [PurchaseController::class, 'show'])->middleware('permission:view-purchases');
            Route::put('/{id}',          [PurchaseController::class, 'update'])->middleware('permission:manage-purchases');
            Route::post('/',                   [PurchaseController::class, 'store'])->middleware('permission:manage-purchases');

            // Receiving & payments
            Route::post('/{purchase}/receive',     [PurchaseController::class, 'receive'])->middleware('permission:manage-purchases');
            Route::post('/{purchase}/payments',    [PurchaseController::class, 'addPayment'])->middleware('permission:manage-purchases');
            Route::put('/{purchase}/payments/{payment}', [PurchaseController::class, 'updatePayment'])->middleware('permission:manage-purchases');
            Route::delete('/{purchase}/payments/{payment}', [PurchaseController::class, 'deletePayment'])->middleware('permission:manage-purchases');

            // Items (line management)
            Route::post('/{purchase}/items',       [PurchaseController::class, 'addItem'])->middleware('permission:manage-purchases');
            Route::put('/{purchase}/items/{item}', [PurchaseController::class, 'updateItem'])->middleware('permission:manage-purchases');
            Route::delete('/{purchase}/items/{item}', [PurchaseController::class, 'deleteItem'])->middleware('permission:manage-purchases');

            Route::post('/{purchase}/cancel',      [PurchaseController::class, 'cancel'])->middleware('permission:manage-purchases');
        });

        Route::prefix('purchase-claims')->group(function () {
            Route::get('/',        [PurchaseClaimController::class, 'index'])->middleware('permission:manage-purchases');
            Route::get('/{id}',    [PurchaseClaimController::class, 'show'])->middleware('permission:manage-purchases');
            Route::post('/',       [PurchaseClaimController::class, 'store'])->middleware('permission:manage-purchases');
            Route::post('/{id}/approve', [PurchaseClaimController::class, 'approve'])->middleware('permission:manage-purchases');
            Route::post('/{id}/receipt', [PurchaseClaimController::class, 'receipt'])->middleware('permission:manage-purchases');
            Route::post('/{id}/reject',  [PurchaseClaimController::class, 'reject'])->middleware('permission:manage-purchases');
            Route::post('/{id}/close',   [PurchaseClaimController::class, 'close'])->middleware('permission:manage-purchases');
        });
        Route::prefix('cashbook')->middleware('permission:view-cashbook')->group(function () {
            Route::get('/', [CashBookController::class, 'index']);
            Route::get('/daily-summary', [CashBookController::class, 'dailySummary']);
            Route::post('/expense', [CashBookController::class, 'storeExpense'])->middleware('permission:manage-cashbook');
            Route::get('/day-details', [CashbookController::class, 'dailyDetails']);
        });
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::prefix('daybook')->middleware('permission:view-cashbook')->group(function () {
            Route::get('/', [DayBookController::class, 'index']);
            Route::get('/day-details', [DaybookController::class, 'dayDetails']);
            // Route::get('/daily-summary', [CashBookController::class, 'dailySummary']);
            // Route::post('/expense', [CashBookController::class, 'storeExpense'])->middleware('permission:manage-cashbook');
            // Route::get('/day-details', [CashbookController::class, 'dailyDetails']);
        });
    });
});
