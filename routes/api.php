<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CashBookController;
use App\Http\Controllers\Api\V1\CashLedgerController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeliveryBoyController;
use App\Http\Controllers\Api\V1\EnterpriseReportController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PrinterConfigController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseClaimController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleItemController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\SalesReportController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Models\Vendor;
use App\Http\Controllers\Api\V1\RegisterShiftController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test', function () {
    return Vendor::class;
});

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'branch.context', 'branch.subscription'])->group(function () {
        // ── Routes exempt from subscription enforcement ────────────────────────
        // These must remain accessible even when a branch is expired/suspended so
        // the Flutter client can display the lock screen, switch to another branch,
        // log out, or the owner can manage the subscription.
        Route::post('/logout', [AuthController::class, 'logout'])
            ->withoutMiddleware('branch.subscription');
        Route::get('/me', [AuthController::class, 'me'])
            ->withoutMiddleware('branch.subscription');
        Route::post('auth/verify-password', [AuthController::class, 'verifyPassword'])
            ->withoutMiddleware('branch.subscription');
        Route::post('/switch-branch', [AuthController::class, 'switchBranch'])
            ->name('switch-branch')
            ->withoutMiddleware('branch.subscription');

        Route::get('/registers', [RegisterShiftController::class, 'registers'])->middleware('permission:view-register-shifts');
        Route::post('/registers', [RegisterShiftController::class, 'storeRegister'])->middleware('permission:manage-register-shifts');
        Route::put('/registers/{register}', [RegisterShiftController::class, 'updateRegister'])->middleware('permission:manage-register-shifts');
        Route::prefix('register-shifts')->group(function () {
            Route::get('/', [RegisterShiftController::class, 'index'])->middleware('permission:view-register-shifts');
            Route::get('/active', [RegisterShiftController::class, 'active'])->middleware('permission:view-register-shifts');
            Route::post('/open', [RegisterShiftController::class, 'open'])->middleware('permission:open-register-shift');
            Route::get('/{shift}', [RegisterShiftController::class, 'show'])->middleware('permission:view-register-shifts');
            Route::post('/{shift}/cash-movements', [RegisterShiftController::class, 'movement'])->middleware('permission:record-shift-cash-movement');
            Route::post('/{shift}/close', [RegisterShiftController::class, 'close'])->middleware('permission:close-own-register-shift');
            Route::post('/{shift}/force-close', [RegisterShiftController::class, 'forceClose'])->middleware('permission:manage-register-shifts');
        });

        // ── Subscription status (exempt — needed even when branch is locked) ───
        // Allows Flutter to query the branch's subscription state to display the
        // lock screen without being blocked by CheckBranchSubscription itself.
        // The old unauthenticated closure at /app-lock-status is replaced by this
        // authenticated controller method.  The path is preserved as an alias so
        // existing tooling that may poll /app-lock-status does not break.
        Route::get('/subscription/status', [SubscriptionController::class, 'status'])
            ->withoutMiddleware('branch.subscription');
        Route::post('/app-lock-status', [SubscriptionController::class, 'status'])
            ->withoutMiddleware('branch.subscription');

        // ── Owner subscription management (exempt from subscription check) ────
        // These routes are protected by their own isMasterAdmin() gate inside
        // the controller and do not need the per-branch subscription guard.
        Route::prefix('/subscriptions')->group(function () {
            Route::get('/', [SubscriptionController::class, 'index'])
                ->withoutMiddleware('branch.subscription');
            Route::get('/{branchId}', [SubscriptionController::class, 'show'])
                ->withoutMiddleware('branch.subscription');
            Route::put('/{branchId}', [SubscriptionController::class, 'update'])
                ->withoutMiddleware('branch.subscription');
            Route::get('/{branchId}/audit', [SubscriptionController::class, 'audit'])
                ->withoutMiddleware('branch.subscription');
        });

        // Branches
        // GET /branches is exempt: the lock screen "Switch Branch" action needs
        // to list available branches even while the current branch is expired.
        Route::get('/branches', [BranchController::class, 'index'])
            ->middleware('permission:view-branches')
            ->withoutMiddleware('branch.subscription');
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
        Route::prefix('delivery-boys')->group(function () {
            Route::get('/', [DeliveryBoyController::class, 'index']);
            Route::get('/{id}/cash-summary', [DeliveryBoyController::class, 'cashSummary']);
            Route::get('/{id}/orders', [DeliveryBoyController::class, 'orders']);
            Route::get('/{id}/received', [DeliveryBoyController::class, 'received']);
            Route::post('/{id}/received', [DeliveryBoyController::class, 'storeReceived']);
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
        Route::get('/stocks/negative-stock-conflicts', [StockController::class, 'negativeStockConflicts'])
            ->middleware('permission:view-stock');
        Route::post('/stocks/adjust', [StockController::class, 'adjust'])
            ->middleware('permission:adjust-stock');
        Route::post('/stocks/transfer', [StockController::class, 'transfer'])
            ->middleware('permission:adjust-stock');

        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->middleware('permission:view-products');
            Route::post('/', [ProductController::class, 'store'])->middleware('permission:manage-products');
            // Static segments must be registered before the '/{id}' wildcard
            // below, otherwise Laravel matches "/products/export" etc. as
            // show($id = 'export').
            Route::get('/export', [ProductController::class, 'export'])->middleware('permission:view-products');
            Route::get('/import-template', [ProductController::class, 'importTemplate'])->middleware('permission:view-products');
            Route::post('/import', [ProductController::class, 'import'])->middleware('permission:manage-products');
            Route::get('/by-barcode/{code}/{vendor_id?}', [ProductController::class, 'findByBarcode'])->middleware('permission:view-products');
            Route::get('/{id}', [ProductController::class, 'show'])->middleware('permission:view-products');
            Route::put('/{id}', [ProductController::class, 'update'])->middleware('permission:manage-products');
            Route::delete('/{id}', [ProductController::class, 'destroy'])->middleware('permission:manage-products');
        });


        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])->middleware('permission:view-customers');
            Route::post('/', [CustomerController::class, 'store'])->middleware('permission:view-customers');
            Route::get('/{customer}', [CustomerController::class, 'show'])->middleware('permission:view-customers');
            Route::put('/{customer}', [CustomerController::class, 'update'])->middleware('permission:view-customers');
            Route::delete('/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:view-customers');
            Route::get('/{customer}/sales', [CustomerController::class, 'sales'])->middleware('permission:view-customers');
            Route::get('/{customer}/receipts', [CustomerController::class, 'receipts'])->middleware('permission:view-customers');
            Route::post('/{customer}/receipts', [CustomerController::class, 'storeReceipt'])->middleware('permission:manage-receipts');
            Route::get('/{customer}/ledger', [CustomerController::class, 'ledger'])->middleware('permission:view-customers');
        });
        Route::prefix('vendors')->group(function () {
            Route::get('/', [VendorController::class, 'index'])->middleware('permission:view-vendors');
            Route::post('/', [VendorController::class, 'store'])->middleware('permission:manage-vendors');
            Route::get('/{vendor}', [VendorController::class, 'show'])->middleware('permission:view-vendors');
            Route::put('/{vendor}', [VendorController::class, 'update'])->middleware('permission:manage-vendors');
            Route::delete('/{vendor}', [VendorController::class, 'destroy'])->middleware('permission:manage-vendors');
            Route::get('/{vendor}/purchases', [VendorController::class, 'purchases'])->middleware('permission:view-vendors');
            Route::get('/{vendor}/payments', [VendorController::class, 'payments'])->middleware('permission:view-vendors');
            Route::post('/{vendor}/payments', [VendorController::class, 'storePayment'])->middleware('permission:manage-payments');
            Route::get('/{vendor}/ledger', [VendorController::class, 'ledger'])->middleware('permission:view-vendors');
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

        // Offline catalog feed (handover doc G1 / Phase 1). Read-only
        // reference data the Flutter client mirrors into its local
        // catalog_cache.db so a cashier can compose a sale with no
        // connectivity. Gated on create-sales because that's exactly who
        // needs an offline catalog to build a sale.
        Route::prefix('catalog')->middleware('permission:create-sales')->group(function () {
            Route::get('/snapshot', [CatalogController::class, 'snapshot']);
            Route::get('/changes', [CatalogController::class, 'changes']);
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
            // Offline-sync reconciliation (handover doc §1.4). Must be
            // registered before '/{id}' below so it isn't swallowed by the
            // GET show() wildcard — it's fine here since this is POST and
            // show() is GET, but kept alongside store() for clarity.
            Route::post('/verify-batch', [SaleController::class, 'verifyBatch'])->middleware('permission:create-sales');
            Route::get('/{id}', [SaleController::class, 'show'])->middleware('permission:view-sales');
            Route::put('/{id}', [SaleController::class, 'update'])->middleware('permission:manage-sales');
            Route::put('/{id}/delivery-boy', [SaleController::class, 'updateDeliveryBoy'])->middleware('permission:manage-sales');
            Route::prefix('{sale}')->middleware('permission:manage-sales')->group(function () {
                Route::post('payments', [PaymentController::class, 'store']);
                // Route::put('payments/{payment}', [PaymentController::class, 'update']);
                Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);

                // Items
                Route::post('items', [SaleItemController::class, 'store']);               // ADD item
                // Route::put('items/{item}', [SaleItemController::class, 'update']);        // EDIT item
                // Route::delete('items/{item}', [SaleItemController::class, 'destroy']);    // DELETE item
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
            // Route::put('/{purchase}/payments/{payment}', [PurchaseController::class, 'updatePayment'])->middleware('permission:manage-purchases');
            Route::delete('/{purchase}/payments/{payment}', [PurchaseController::class, 'deletePayment'])->middleware('permission:manage-purchases');

            // Items (line management)
            Route::post('/{purchase}/items',       [PurchaseController::class, 'addItem'])->middleware('permission:manage-purchases');
            // Route::put('/{purchase}/items/{item}', [PurchaseController::class, 'updateItem'])->middleware('permission:manage-purchases');
            // Route::delete('/{purchase}/items/{item}', [PurchaseController::class, 'deleteItem'])->middleware('permission:manage-purchases');

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
            Route::get('/day-details', [CashBookController::class, 'dailyDetails']);
        });

        // Unified Cash Ledger: every cash movement (customer receipts, vendor
        // payments, expenses, refunds, claims, Qameti, loans). /transactions
        // and /cash-flow read across the whole journal; / and /{entry} are
        // for the manually-recorded non-sales entries (Qameti, loans, etc.).
        Route::prefix('cash-ledger')->middleware('permission:view-cashbook')->group(function () {
            Route::get('/',             [CashLedgerController::class, 'index']);
            Route::get('/transactions', [CashLedgerController::class, 'transactions']); // unified ledger feed
            Route::get('/cash-flow',    [CashLedgerController::class, 'cashFlow']);
            Route::get('/{entry}',      [CashLedgerController::class, 'show']);

            Route::post('/',             [CashLedgerController::class, 'store'])->middleware('permission:manage-cashbook');
            Route::post('/{entry}/void', [CashLedgerController::class, 'void'])->middleware('permission:manage-cashbook');
        });

        Route::post('/expenses', [ExpenseController::class, 'store']);

        // Day Book: day-by-day view of the SAME unified cash ledger
        // (every cash movement: receipts, vendor payments, expenses, Qameti, loans, refunds)
        Route::prefix('daybook')->middleware('permission:view-cashbook')->group(function () {
            Route::get('/',            [CashLedgerController::class, 'dayBook']);       // per-day opening/in/out/closing
            Route::get('/day-details', [CashLedgerController::class, 'dayBookDetails']); // one day's transactions, labelled
        });

        // /app-lock-status is now handled above as an authenticated alias for
        // /subscription/status — the old hard-coded closure has been removed.
        // The pass_key field has been deleted: it was exposed to every client
        // and served no verified-security purpose.

        // Printer settings: real, persisted, branch-aware (replaces the old
        // hardcoded placeholder values). Any signed-in user can read the
        // settings for their own branch; only master admin can write them.
        Route::post('/printer-config', [PrinterConfigController::class, 'show']);
        Route::get('/printer-config', [PrinterConfigController::class, 'show']);
        Route::get('/printer-config/all', [PrinterConfigController::class, 'index']);
        Route::get('/printer-config/templates', [PrinterConfigController::class, 'templates']);
        Route::post('/printer-config/save', [PrinterConfigController::class, 'save'])->name('printer-config.save');
        Route::post('/printer-config/test', [PrinterConfigController::class, 'test'])->name('printer-config.test');

        Route::prefix('/reports')->middleware('permission:view-reports')->group(function () {
            // Enterprise-grade unified reporting API.
            // JSON:  GET /api/v1/reports/run/{report}?from=2026-06-01 09:00:00&to=2026-06-12 23:59:59
            // XLSX:  GET /api/v1/reports/export/{report}?format=xlsx&from=...
            // PDF:   GET /api/v1/reports/export/{report}?format=pdf&orientation=landscape&from=...
            Route::get('/catalog', [EnterpriseReportController::class, 'catalog']);
            Route::get('/run/{report}', [EnterpriseReportController::class, 'show']);
            Route::get('/export/{report}', [EnterpriseReportController::class, 'export']);

            // Existing report endpoints kept for backward compatibility with current frontend.
            Route::prefix('/sales')->group(function () {
                Route::get('/daily-summary', [SalesReportController::class, 'dailySummary']);
                Route::get('/top-bottom',    [SalesReportController::class, 'topBottom']);
            });
            Route::get('/ledger', [ReportController::class, 'ledger']);
            Route::get('/cashbook-daily', [ReportController::class, 'cashbookDaily']);
            Route::get('/stock-movement', [ReportController::class, 'stockMovement']);
            Route::get('/profit-loss', [ReportController::class, 'profitLoss']);
            Route::get('/return-analytics', [ReportController::class, 'returnAnalytics']);
        });
    });
});
