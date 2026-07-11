<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Customer;
use App\Models\Product;
use App\Services\BranchContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only reference-data feed for the offline catalog cache
 * (handover doc G1 / Phase 1).
 *
 * The Flutter client keeps a local SQLite replica (catalog_cache.db) of the
 * products, prices, tax and customers it needs to *compose* a sale offline.
 * This controller is the source that replica pulls from:
 *
 *   - snapshot()  full current state, used on first sync (empty cache).
 *   - changes()   only rows changed since a prior `catalog_version`, plus
 *                 tombstones for soft-deleted rows so the device can purge.
 *
 * Pricing and tax live directly on the products row in this schema (there is
 * no per-branch price table), so they ride along as columns rather than in a
 * separate prices/tax_rules feed. Everything here is a projection the device
 * only ever reads; catalog data is never edited offline (that's what keeps
 * the sync model conflict-free — see handover doc §3.2).
 */
class CatalogController extends Controller
{
    /** Columns the device needs to render a product in a picker + price it. */
    private const PRODUCT_COLUMNS = [
        'id', 'branch_id', 'sku', 'barcode', 'name',
        'price', 'cost_price', 'wholesale_price',
        'tax_rate', 'tax_inclusive', 'discount',
        'vendor_id', 'category_id', 'is_active', 'updated_at',
    ];

    /** Columns the device needs to pick / snapshot a customer onto a sale. */
    private const CUSTOMER_COLUMNS = [
        'id', 'first_name', 'last_name', 'phone', 'email',
        'address', 'status', 'branch_id', 'updated_at',
    ];

    /**
     * GET /catalog/snapshot?branch_id=
     *
     * Full current catalog for the branch. Use when the local cache is empty
     * (first login on a device, or after a cache reset).
     */
    public function snapshot(Request $request, BranchContextService $branches)
    {
        $branchId = $this->resolveBranchId($request, $branches);

        // Capture the cursor BEFORE reading, so any write that lands during
        // the read is simply picked up by the next delta rather than missed.
        $version = now();

        $products  = $this->productQuery($branchId)
            ->orderBy('id')
            ->get(self::PRODUCT_COLUMNS);

        $customers = $this->customerQuery($branchId)
            ->orderBy('id')
            ->get(self::CUSTOMER_COLUMNS);

        return ApiResponse::success([
            'catalog_version' => $version->toIso8601String(),
            'branch_id'       => $branchId,
            'products'        => $products,
            'customers'       => $customers,
            // Present so the delta and snapshot payloads have the same shape;
            // a fresh snapshot has nothing to purge.
            'deleted_products'  => [],
            'deleted_customers' => [],
        ], 'Catalog snapshot');
    }

    /**
     * GET /catalog/changes?since=<catalog_version>&branch_id=
     *
     * Everything that changed since `since`: upserted rows (updated_at in the
     * window) and tombstones (rows soft-deleted in the window) so the device
     * can drop products/customers that were deactivated server-side.
     */
    public function changes(Request $request, BranchContextService $branches)
    {
        $data = $request->validate([
            'since' => 'required|date',
        ]);

        $branchId = $this->resolveBranchId($request, $branches);
        $since    = Carbon::parse($data['since']);
        $version  = now();

        // Upserts: changed within (since, version]. The upper bound stops us
        // from returning a row whose updated_at is newer than the cursor we
        // hand back, which would otherwise be re-sent on the next delta too.
        $products = $this->productQuery($branchId)
            ->where('updated_at', '>', $since)
            ->where('updated_at', '<=', $version)
            ->orderBy('updated_at')
            ->get(self::PRODUCT_COLUMNS);

        $customers = $this->customerQuery($branchId)
            ->where('updated_at', '>', $since)
            ->where('updated_at', '<=', $version)
            ->orderBy('updated_at')
            ->get(self::CUSTOMER_COLUMNS);

        // Tombstones: soft-deleted within the window. product_stocks / sales
        // history on the server are untouched; the device just stops offering
        // these in its pickers.
        $deletedProducts = Product::onlyTrashed()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('deleted_at', '>', $since)
            ->where('deleted_at', '<=', $version)
            ->pluck('id');

        $deletedCustomers = Customer::onlyTrashed()
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->where('deleted_at', '>', $since)
            ->where('deleted_at', '<=', $version)
            ->pluck('id');

        return ApiResponse::success([
            'catalog_version'   => $version->toIso8601String(),
            'branch_id'         => $branchId,
            'products'          => $products,
            'customers'         => $customers,
            'deleted_products'  => $deletedProducts,
            'deleted_customers' => $deletedCustomers,
        ], 'Catalog changes');
    }

    /**
     * Products in-scope for a branch. Mirrors ProductController's branch
     * scoping: products are branch-owned, so a null branch yields nothing.
     */
    private function productQuery(?int $branchId)
    {
        return Product::query()
            ->when(
                $branchId,
                fn ($q) => $q->where('branch_id', $branchId),
                fn ($q) => $q->whereRaw('1 = 0')
            );
    }

    /**
     * Customers in-scope for a branch. Unlike products, a customer may be
     * global (null branch_id) and usable from any branch — SaleController
     * accepts those — so include both the branch's own and global customers.
     */
    private function customerQuery(?int $branchId)
    {
        return Customer::query()
            ->when(
                $branchId,
                fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id'))
            );
    }

    /**
     * The branch to scope by: an explicit ?branch_id= (so a master admin can
     * warm any branch's cache) falling back to the caller's own branch.
     */
    private function resolveBranchId(Request $request, BranchContextService $branches): ?int
    {
        $requested = $request->integer('branch_id');
        if ($requested > 0) {
            $branches->assertCanAccessBranch($request, $requested);
            return $requested;
        }

        return $branches->effectiveBranchId($request);
    }
}
