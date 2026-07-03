<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EnterpriseReportService
{
    public function catalog(): array
    {
        return [
            'sales' => [
                'sales-summary' => 'Sales Summary',
                'sales-detail' => 'Sales Detail',
                'sales-by-product' => 'Sales by Product',
                'sales-by-category' => 'Sales by Category',
                'sales-by-brand' => 'Sales by Brand',
                'sales-by-customer' => 'Sales by Customer',
                'sales-by-salesman' => 'Sales by Salesman / Cashier',
                'sales-by-hour' => 'Hourly Sales',
                'sales-by-payment-method' => 'Payment Collection by Method',
                'delivery-boy-cash' => 'Delivery Boy Cash Report',
                'discount-report' => 'Discount Report',
                'tax-report' => 'Tax Report',
                'sale-return-summary' => 'Sales Return Summary',
                'sale-return-detail' => 'Sales Return Detail',
            ],
            'purchases' => [
                'purchase-summary' => 'Purchase Summary',
                'purchase-detail' => 'Purchase Detail',
                'purchase-by-product' => 'Purchase by Product',
                'purchase-by-vendor' => 'Purchase by Vendor',
                'vendor-payment-summary' => 'Vendor Payment Summary',
                'purchase-claim-summary' => 'Purchase Claim Summary',
                'purchase-claim-detail' => 'Purchase Claim Detail',
            ],
            'inventory' => [
                'current-stock' => 'Current Stock',
                'low-stock' => 'Low Stock / Reorder Report',
                'stock-valuation' => 'Stock Valuation',
                'stock-movement' => 'Stock Movement Ledger',
                'inventory-adjustment' => 'Inventory Adjustment Report',
            ],
            'accounting' => [
                'cashbook' => 'Cashbook',
                'daybook' => 'Daybook',
                'profit-loss' => 'Profit & Loss',
                'customer-receivables' => 'Customer Receivables / AR Aging Base',
                'vendor-payables' => 'Vendor Payables / AP Aging Base',
                'trial-balance' => 'Trial Balance',
                'ledger-detail' => 'Ledger Detail',
            ],
        ];
    }

    public function run(string $key, array $input = [], bool $export = false): array
    {
        $key = str($key)->lower()->replace('_', '-')->toString();
        $filters = $this->filters($input, $export);

        $report = match ($key) {
            'sales-summary' => $this->salesSummary($filters, $export),
            'sales-detail' => $this->salesDetail($filters, $export),
            'sales-by-product' => $this->salesByProduct($filters, $export),
            'sales-by-category' => $this->salesGroupedByDimension($filters, $export, 'category'),
            'sales-by-brand' => $this->salesGroupedByDimension($filters, $export, 'brand'),
            'sales-by-customer' => $this->salesGroupedByDimension($filters, $export, 'customer'),
            'sales-by-salesman' => $this->salesGroupedByDimension($filters, $export, 'salesman'),
            'sales-by-hour' => $this->salesByHour($filters, $export),
            'sales-by-payment-method' => $this->paymentCollection($filters, $export),
            'delivery-boy-cash' => $this->deliveryBoyCash($filters, $export),
            'discount-report' => $this->discountReport($filters, $export),
            'tax-report' => $this->taxReport($filters, $export),
            'sale-return-summary' => $this->saleReturnSummary($filters, $export),
            'sale-return-detail' => $this->saleReturnDetail($filters, $export),
            'purchase-summary' => $this->purchaseSummary($filters, $export),
            'purchase-detail' => $this->purchaseDetail($filters, $export),
            'purchase-by-product' => $this->purchaseByProduct($filters, $export),
            'purchase-by-vendor' => $this->purchaseGroupedByDimension($filters, $export, 'vendor'),
            'vendor-payment-summary' => $this->vendorPaymentSummary($filters, $export),
            'purchase-claim-summary' => $this->purchaseClaimSummary($filters, $export),
            'purchase-claim-detail' => $this->purchaseClaimDetail($filters, $export),
            'current-stock' => $this->currentStock($filters, $export, false),
            'low-stock' => $this->currentStock($filters, $export, true),
            'stock-valuation' => $this->stockValuation($filters, $export),
            'stock-movement' => $this->stockMovement($filters, $export, false),
            'inventory-adjustment' => $this->stockMovement($filters, $export, true),
            'cashbook' => $this->cashbook($filters, $export),
            'daybook' => $this->daybook($filters, $export),
            'profit-loss' => $this->profitLoss($filters, $export),
            'customer-receivables' => $this->customerReceivables($filters, $export),
            'vendor-payables' => $this->vendorPayables($filters, $export),
            'trial-balance' => $this->trialBalance($filters, $export),
            'ledger-detail' => $this->ledgerDetail($filters, $export),
            default => abort(404, 'Unknown report key: ' . $key),
        };

        $report = $this->withoutBranchExposure($report);

        $report['key'] = $key;
        $report['filters'] = $this->publicFilters($filters);
        $report['generated_at'] = now()->toDateTimeString();
        $report['export_ready'] = ['xlsx', 'pdf'];

        return $report;
    }

    private function filters(array $input, bool $export = false): array
    {
        $from = $this->parseFrom($input['from'] ?? null);
        $to = $this->parseTo($input['to'] ?? null);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $this->nullableInt($input['branch_id'] ?? null),
            'customer_id' => $this->nullableInt($input['customer_id'] ?? null),
            'vendor_id' => $this->nullableInt($input['vendor_id'] ?? null),
            'product_id' => $this->nullableInt($input['product_id'] ?? null),
            'category_id' => $this->nullableInt($input['category_id'] ?? null),
            'brand_id' => $this->nullableInt($input['brand_id'] ?? null),
            'salesman_id' => $this->nullableInt($input['salesman_id'] ?? null),
            'delivery_boy_id' => $this->nullableInt($input['delivery_boy_id'] ?? null),
            'created_by' => $this->nullableInt($input['created_by'] ?? null),
            'account_id' => $this->nullableInt($input['account_id'] ?? null),
            'party_id' => $this->nullableInt($input['party_id'] ?? null),
            'party_type' => $input['party_type'] ?? null,
            'status' => $input['status'] ?? null,
            'method' => $input['method'] ?? null,
            'sale_type' => $input['sale_type'] ?? null,
            'stock_type' => $input['stock_type'] ?? null,
            'search' => $input['search'] ?? null,
            'sort_by' => $input['sort_by'] ?? null,
            'direction' => strtolower($input['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int)($input['page'] ?? 1)),
            // On-screen pagination stays capped at 500 for snappy UI
            // responses; exports intentionally request a much larger
            // per_page (see enterprise_reports_workspace_screen.dart, which
            // sends 1000) to pull the full filtered dataset in one request,
            // so that path gets a higher ceiling instead of silently
            // truncating the exported report to 500 rows.
            'per_page' => max(1, min($export ? 5000 : 500, (int)($input['per_page'] ?? 50))),
        ];
    }

    private function salesSummary(array $f, bool $export): array
    {
        $date = $this->dateSql('s.created_at');
        $sales = DB::table('sales as s')
            ->selectRaw("$date as report_date")
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('COALESCE(SUM(s.subtotal),0) as subtotal')
            ->selectRaw('COALESCE(SUM(s.discount),0) as discount')
            ->selectRaw('COALESCE(SUM(s.tax),0) as tax')
            ->selectRaw('COALESCE(SUM(s.delivery),0) as delivery')
            ->selectRaw('COALESCE(SUM(s.total),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(s.cogs),0) as cogs')
            ->selectRaw('COALESCE(SUM(s.gross_profit),0) as gross_profit')
            ->whereNull('s.deleted_at');

        $this->applyDateRange($sales, 's.created_at', $f);
        $this->applySalesFilters($sales, $f, 's');
        $sales->groupByRaw($date)->orderBy('report_date', $f['direction']);

        $rows = $sales->get()->map(fn ($r) => [
            'date' => $r->report_date,
            'invoices' => (int)$r->invoices,
            'subtotal' => $this->money($r->subtotal),
            'discount' => $this->money($r->discount),
            'tax' => $this->money($r->tax),
            'delivery' => $this->money($r->delivery),
            'gross_sales' => $this->money($r->gross_sales),
            'returns' => 0.0,
            'net_sales' => $this->money($r->gross_sales),
            'cogs' => $this->money($r->cogs),
            'gross_profit' => $this->money($r->gross_profit),
        ])->keyBy('date');

        $returns = DB::table('sale_returns as sr')
            ->selectRaw($this->dateSql('sr.created_at') . ' as report_date')
            ->selectRaw('COALESCE(SUM(sr.total),0) as returns')
            ->leftJoin('sales as s', 's.id', '=', 'sr.sale_id');
        $this->applyDateRange($returns, 'sr.created_at', $f);
        $this->applyReturnFilters($returns, $f, 'sr', 's');
        $returns->groupByRaw($this->dateSql('sr.created_at'));
        foreach ($returns->get() as $r) {
            $existing = $rows->get($r->report_date, [
                'date' => $r->report_date, 'invoices' => 0, 'subtotal' => 0.0, 'discount' => 0.0,
                'tax' => 0.0, 'delivery' => 0.0, 'gross_sales' => 0.0, 'returns' => 0.0,
                'net_sales' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0,
            ]);
            $existing['returns'] = $this->money($existing['returns'] + (float)$r->returns);
            $existing['net_sales'] = $this->money($existing['gross_sales'] - $existing['returns']);
            $rows->put($r->report_date, $existing);
        }

        // Inline returns are stored as negative sale_items on a normal sale.
        // The sale header total is already net of those negative lines, so we add the
        // absolute return amount back to gross/subtotal and also show it under returns.
        $inlineReturns = $this->inlineReturnItemsBase($f, true)
            ->selectRaw($this->dateSql('s.created_at') . ' as report_date')
            ->selectRaw('COALESCE(SUM(ABS(si.total)),0) as returns')
            ->groupByRaw($this->dateSql('s.created_at'));

        foreach ($inlineReturns->get() as $r) {
            $amount = $this->money($r->returns);
            $existing = $rows->get($r->report_date, [
                'date' => $r->report_date, 'invoices' => 0, 'subtotal' => 0.0, 'discount' => 0.0,
                'tax' => 0.0, 'delivery' => 0.0, 'gross_sales' => 0.0, 'returns' => 0.0,
                'net_sales' => 0.0, 'cogs' => 0.0, 'gross_profit' => 0.0,
            ]);
            $existing['subtotal'] = $this->money($existing['subtotal'] + $amount);
            $existing['gross_sales'] = $this->money($existing['gross_sales'] + $amount);
            $existing['returns'] = $this->money($existing['returns'] + $amount);
            $existing['net_sales'] = $this->money($existing['gross_sales'] - $existing['returns']);
            $rows->put($r->report_date, $existing);
        }

        $rows = $rows->values()->sortBy('date', SORT_REGULAR, $f['direction'] === 'desc')->values();
        $rowsArray = $rows->all();

        return $this->report('Sales Summary', $this->salesSummaryColumns(), $rowsArray, $this->sumTotals($rowsArray, [
            'invoices', 'subtotal', 'discount', 'tax', 'delivery', 'gross_sales', 'returns', 'net_sales', 'cogs', 'gross_profit'
        ]), $f, $export);
    }

    private function salesDetail(array $f, bool $export): array
    {
        $q = DB::table('sales as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 's.salesman_id')
            ->whereNull('s.deleted_at');

        $this->applyDateRange($q, 's.created_at', $f);
        $this->applySalesFilters($q, $f, 's');
        $this->applySearch($q, $f, ['s.invoice_no', 'c.first_name', 'c.last_name', 'c.phone']);

        $q->selectRaw('s.id, s.invoice_no, s.created_at, s.invoice_date, s.sale_type')
            ->selectRaw($this->nameSql('c.first_name', 'c.last_name') . ' as customer')
            ->selectRaw('u.name as salesman')
            ->selectRaw('s.subtotal, s.discount, s.tax, s.delivery, s.total, s.cogs, s.gross_profit')
            ->orderBy($this->sortColumn($f, ['created_at' => 's.created_at', 'invoice_no' => 's.invoice_no', 'total' => 's.total'], 's.created_at'), $f['direction']);

        $totals = $this->queryTotals($q, ['subtotal', 'discount', 'tax', 'delivery', 'total', 'cogs', 'gross_profit']);

        return $this->reportFromQuery('Sales Detail', [
            ['key' => 'invoice_no', 'label' => 'Invoice No'],
            ['key' => 'created_at', 'label' => 'Created At'],
            ['key' => 'invoice_date', 'label' => 'Invoice Date'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'salesman', 'label' => 'Salesman'],
            ['key' => 'sale_type', 'label' => 'Sale Type'],
            ['key' => 'subtotal', 'label' => 'Subtotal'],
            ['key' => 'discount', 'label' => 'Discount'],
            ['key' => 'tax', 'label' => 'Tax'],
            ['key' => 'delivery', 'label' => 'Delivery'],
            ['key' => 'total', 'label' => 'Total'],
            ['key' => 'cogs', 'label' => 'COGS'],
            ['key' => 'gross_profit', 'label' => 'Gross Profit'],
        ], $q, $totals, $f, $export);
    }

    private function salesByProduct(array $f, bool $export): array
    {
        $q = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->whereNull('s.deleted_at');
        $this->applyDateRange($q, 's.created_at', $f);
        $this->applySalesFilters($q, $f, 's');
        $this->applyProductFilters($q, $f, 'p');
        $this->applySearch($q, $f, ['p.name', 'p.sku', 'p.barcode']);
        $q->selectRaw('p.id as product_id, p.sku, p.barcode, p.name as product, cat.name as category, br.name as brand')
            ->selectRaw('COALESCE(SUM(si.quantity),0) as quantity')
            ->selectRaw('COALESCE(SUM(si.total),0) as revenue')
            ->selectRaw('COALESCE(SUM(si.line_cost),0) as cogs')
            ->selectRaw('COALESCE(SUM(si.total - si.line_cost),0) as gross_profit')
            ->groupBy('p.id', 'p.sku', 'p.barcode', 'p.name', 'cat.name', 'br.name')
            ->orderBy($this->sortColumn($f, ['quantity' => 'quantity', 'revenue' => 'revenue', 'gross_profit' => 'gross_profit', 'product' => 'product'], 'revenue'), $f['direction']);

        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Sales by Product', [
            ['key' => 'product_id', 'label' => 'Product ID'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'barcode', 'label' => 'Barcode'],
            ['key' => 'product', 'label' => 'Product'], ['key' => 'category', 'label' => 'Category'], ['key' => 'brand', 'label' => 'Brand'],
            ['key' => 'quantity', 'label' => 'Quantity'], ['key' => 'revenue', 'label' => 'Revenue'], ['key' => 'cogs', 'label' => 'COGS'], ['key' => 'gross_profit', 'label' => 'Gross Profit'],
        ], $rows, $this->sumTotals($rows, ['quantity', 'revenue', 'cogs', 'gross_profit']), $f, $export);
    }

    private function salesGroupedByDimension(array $f, bool $export, string $dimension): array
    {
        $q = DB::table('sales as s')->whereNull('s.deleted_at');
        $label = match ($dimension) {
            'category' => ['Sales by Category', 'cat.name', 'category', fn($q) => $q->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')->leftJoin('products as p', 'p.id', '=', 'si.product_id')->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')],
            'brand' => ['Sales by Brand', 'br.name', 'brand', fn($q) => $q->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')->leftJoin('products as p', 'p.id', '=', 'si.product_id')->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')],
            'customer' => ['Sales by Customer', $this->nameSql('c.first_name', 'c.last_name'), 'customer', fn($q) => $q->leftJoin('customers as c', 'c.id', '=', 's.customer_id')],
            'salesman' => ['Sales by Salesman / Cashier', 'u.name', 'salesman', fn($q) => $q->leftJoin('users as u', 'u.id', '=', 's.salesman_id')],
            default => ['Sales by Branch', 'b.name', 'branch', fn($q) => $q->leftJoin('branches as b', 'b.id', '=', 's.branch_id')],
        };
        $label[3]($q);
        $this->applyDateRange($q, 's.created_at', $f);
        $this->applySalesFilters($q, $f, 's');
        if (in_array($dimension, ['category', 'brand'], true)) {
            $this->applyProductFilters($q, $f, 'p');
        }
        $nameExpr = $label[1];
        $alias = $label[2];
        $groupCols = $dimension === 'category' ? ['cat.id', 'cat.name'] : ($dimension === 'brand' ? ['br.id', 'br.name'] : []);
        $q->selectRaw("COALESCE($nameExpr, 'Walk-in / Unassigned') as $alias")
            ->selectRaw('COUNT(DISTINCT s.id) as invoices')
            ->selectRaw($dimension === 'category' || $dimension === 'brand' ? 'COALESCE(SUM(si.quantity),0) as quantity' : '0 as quantity')
            ->selectRaw($dimension === 'category' || $dimension === 'brand' ? 'COALESCE(SUM(si.total),0) as sales_total' : 'COALESCE(SUM(s.total),0) as sales_total')
            ->selectRaw($dimension === 'category' || $dimension === 'brand' ? 'COALESCE(SUM(si.line_cost),0) as cogs' : 'COALESCE(SUM(s.cogs),0) as cogs')
            ->selectRaw($dimension === 'category' || $dimension === 'brand' ? 'COALESCE(SUM(si.total - si.line_cost),0) as gross_profit' : 'COALESCE(SUM(s.gross_profit),0) as gross_profit');
        if ($groupCols) {
            foreach ($groupCols as $col) {
                $q->groupBy($col);
            }
        } else {
            $q->groupByRaw($nameExpr);
        }
        $q->orderBy($this->sortColumn($f, ['invoices' => 'invoices', 'quantity' => 'quantity', 'sales_total' => 'sales_total', 'gross_profit' => 'gross_profit'], 'sales_total'), $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report($label[0], [
            ['key' => $alias, 'label' => ucwords(str_replace('_', ' ', $alias))],
            ['key' => 'invoices', 'label' => 'Invoices'],
            ['key' => 'quantity', 'label' => 'Quantity'],
            ['key' => 'sales_total', 'label' => 'Sales Total'],
            ['key' => 'cogs', 'label' => 'COGS'],
            ['key' => 'gross_profit', 'label' => 'Gross Profit'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'quantity', 'sales_total', 'cogs', 'gross_profit']), $f, $export);
    }

    private function salesByHour(array $f, bool $export): array
    {
        $date = $this->dateSql('s.created_at');
        $hour = $this->hourSql('s.created_at');
        $q = DB::table('sales as s')->whereNull('s.deleted_at');
        $this->applyDateRange($q, 's.created_at', $f);
        $this->applySalesFilters($q, $f, 's');
        $q->selectRaw("$date as sale_date, $hour as hour")
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total),0) as sales_total, COALESCE(SUM(gross_profit),0) as gross_profit')
            ->groupByRaw("$date, $hour")
            ->orderBy('sale_date', $f['direction'])->orderBy('hour');
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Hourly Sales', [
            ['key' => 'sale_date', 'label' => 'Date'], ['key' => 'hour', 'label' => 'Hour'],
            ['key' => 'invoices', 'label' => 'Invoices'], ['key' => 'sales_total', 'label' => 'Sales Total'], ['key' => 'gross_profit', 'label' => 'Gross Profit'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'sales_total', 'gross_profit']), $f, $export);
    }

    private function paymentCollection(array $f, bool $export): array
    {
        $q = DB::table('receipts as r')
            ->leftJoin('sales as s', 's.id', '=', 'r.sale_id')
            ->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id');
        $this->applyDateRange($q, 'r.created_at', $f);
        $this->applyWhere($q, 'r.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'r.customer_id', $f['customer_id']);
        $this->applyWhere($q, 'r.method', $f['method']);
        $this->applySearch($q, $f, ['r.reference', 's.invoice_no', 'c.first_name', 'c.phone']);
        $q->selectRaw('r.method, b.name as branch')
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw('COALESCE(SUM(r.amount),0) as amount')
            ->groupBy('r.method', 'b.name')
            ->orderBy('amount', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Payment Collection by Method', [
            ['key' => 'method', 'label' => 'Method'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'payments', 'label' => 'Payments'], ['key' => 'amount', 'label' => 'Amount'],
        ], $rows, $this->sumTotals($rows, ['payments', 'amount']), $f, $export);
    }

    private function deliveryBoyCash(array $f, bool $export): array
    {
        $accountId = DB::table('accounts')->where('code', \App\Services\DeliveryBoyLedgerService::ACCOUNT_CODE)->value('id');

        if (!$accountId) {
            return $this->report('Delivery Boy Cash Report', [
                ['key' => 'delivery_boy_id', 'label' => 'Delivery Boy ID'], ['key' => 'delivery_boy', 'label' => 'Delivery Boy'],
                ['key' => 'orders', 'label' => 'Ledger Debits'], ['key' => 'orders_total', 'label' => 'Amount To Receive'],
                ['key' => 'collected', 'label' => 'Received'], ['key' => 'pending', 'label' => 'Pending'],
            ], [], ['orders' => 0, 'orders_total' => 0.0, 'collected' => 0.0, 'pending' => 0.0], $f, $export);
        }

        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->join('users as u', 'u.id', '=', 'jp.party_id')
            ->where('jp.account_id', (int) $accountId)
            ->where('jp.party_type', \App\Models\User::class);

        $this->applyDateRange($q, 'je.entry_date', $f);
        $this->applyWhere($q, 'je.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'jp.party_id', $f['delivery_boy_id']);

        $q->selectRaw('u.id as delivery_boy_id, u.name as delivery_boy')
            ->selectRaw('SUM(CASE WHEN COALESCE(jp.debit,0) > 0 THEN 1 ELSE 0 END) as orders')
            ->selectRaw('COALESCE(SUM(jp.debit),0) as orders_total')
            ->selectRaw('COALESCE(SUM(jp.credit),0) as collected')
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit),0) as pending')
            ->groupBy('u.id', 'u.name')
            ->havingRaw('ABS(pending) >= 0.005')
            ->orderBy('pending', 'desc');

        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Delivery Boy Cash Report', [
            ['key' => 'delivery_boy_id', 'label' => 'Delivery Boy ID'], ['key' => 'delivery_boy', 'label' => 'Delivery Boy'],
            ['key' => 'orders', 'label' => 'Ledger Debits'], ['key' => 'orders_total', 'label' => 'Amount To Receive'],
            ['key' => 'collected', 'label' => 'Received'], ['key' => 'pending', 'label' => 'Pending'],
        ], $rows, $this->sumTotals($rows, ['orders', 'orders_total', 'collected', 'pending']), $f, $export);
    }

    private function discountReport(array $f, bool $export): array
    {
        $q = DB::table('sales as s')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->whereNull('s.deleted_at')
            ->where('s.discount', '>', 0);
        $this->applyDateRange($q, 's.created_at', $f);
        $this->applySalesFilters($q, $f, 's');
        $q->selectRaw('s.invoice_no, s.created_at, b.name as branch')
            ->selectRaw($this->nameSql('c.first_name', 'c.last_name') . ' as customer')
            ->selectRaw('s.subtotal, s.discount, s.total')
            ->orderBy('s.created_at', $f['direction']);
        $totals = $this->queryTotals($q, ['subtotal', 'discount', 'total']);
        return $this->reportFromQuery('Discount Report', [
            ['key' => 'invoice_no', 'label' => 'Invoice No'], ['key' => 'created_at', 'label' => 'Created At'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'discount', 'label' => 'Discount'], ['key' => 'total', 'label' => 'Total'],
        ], $q, $totals, $f, $export);
    }

    private function taxReport(array $f, bool $export): array
    {
        $date = $this->dateSql('s.created_at');
        $q = DB::table('sales as s')->whereNull('s.deleted_at');
        $this->applyDateRange($q, 's.created_at', $f);
        $this->applySalesFilters($q, $f, 's');
        $q->selectRaw("$date as date")
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(subtotal),0) as taxable_sales, COALESCE(SUM(tax),0) as output_tax, COALESCE(SUM(total),0) as total')
            ->groupByRaw($date)->orderBy('date', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Tax Report', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'invoices', 'label' => 'Invoices'], ['key' => 'taxable_sales', 'label' => 'Taxable Sales'], ['key' => 'output_tax', 'label' => 'Output Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'taxable_sales', 'output_tax', 'total']), $f, $export);
    }

    private function saleReturnSummary(array $f, bool $export): array
    {
        $date = $this->dateSql('sr.created_at');
        $q = DB::table('sale_returns as sr')->leftJoin('sales as s', 's.id', '=', 'sr.sale_id');
        $this->applyDateRange($q, 'sr.created_at', $f);
        $this->applyReturnFilters($q, $f, 'sr', 's');
        $q->selectRaw("$date as date")
            ->selectRaw('COUNT(*) as returns_count, COALESCE(SUM(sr.subtotal),0) as subtotal, COALESCE(SUM(sr.tax),0) as tax, COALESCE(SUM(sr.total),0) as total')
            ->groupByRaw($date)->orderBy('date', $f['direction']);

        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->keyBy('date');

        $inlineDate = $this->dateSql('s.created_at');
        $inline = $this->inlineReturnItemsBase($f)
            ->selectRaw("$inlineDate as date")
            ->selectRaw('COUNT(DISTINCT s.id) as returns_count')
            ->selectRaw('COALESCE(SUM(ABS(si.total)),0) as subtotal')
            ->selectRaw('0 as tax')
            ->selectRaw('COALESCE(SUM(ABS(si.total)),0) as total')
            ->groupByRaw($inlineDate)
            ->orderBy('date', $f['direction']);

        foreach ($inline->get() as $r) {
            $existing = $rows->get($r->date, [
                'date' => $r->date,
                'returns_count' => 0,
                'subtotal' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
            ]);

            $existing['returns_count'] = (int)$existing['returns_count'] + (int)$r->returns_count;
            $existing['subtotal'] = $this->money($existing['subtotal'] + (float)$r->subtotal);
            $existing['tax'] = $this->money($existing['tax'] + (float)$r->tax);
            $existing['total'] = $this->money($existing['total'] + (float)$r->total);

            $rows->put($r->date, $existing);
        }

        $rows = $rows->values()->sortBy('date', SORT_REGULAR, $f['direction'] === 'desc')->values()->all();

        return $this->report('Sales Return Summary', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'returns_count', 'label' => 'Returns'], ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $rows, $this->sumTotals($rows, ['returns_count', 'subtotal', 'tax', 'total']), $f, $export);
    }

    private function saleReturnDetail(array $f, bool $export): array
    {
        $formal = DB::table('sale_returns as sr')
            ->leftJoin('sales as s', 's.id', '=', 'sr.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 'sr.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sr.branch_id');
        $this->applyDateRange($formal, 'sr.created_at', $f);
        $this->applyReturnFilters($formal, $f, 'sr', 's');
        $formal->selectRaw('sr.return_no, sr.created_at, s.invoice_no, b.name as branch')
            ->selectRaw($this->nameSql('c.first_name', 'c.last_name') . ' as customer')
            ->selectRaw('sr.status, sr.reason, sr.subtotal, sr.tax, sr.total');

        $formalRows = $formal->get()->map(fn($r) => $this->roundRow((array)$r));

        $inline = $this->inlineReturnItemsBase($f)
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->selectRaw('s.id as sale_id, s.invoice_no, s.created_at, b.name as branch')
            ->selectRaw($this->nameSql('c.first_name', 'c.last_name') . ' as customer')
            ->selectRaw('COALESCE(SUM(ABS(si.total)),0) as subtotal')
            ->selectRaw('0 as tax')
            ->selectRaw('COALESCE(SUM(ABS(si.total)),0) as total')
            ->groupBy('s.id', 's.invoice_no', 's.created_at', 'b.name', 'c.first_name', 'c.last_name');

        $inlineRows = $inline->get()->map(function ($r) {
            return $this->roundRow([
                'return_no' => 'INLINE-' . $r->invoice_no,
                'created_at' => $r->created_at,
                'invoice_no' => $r->invoice_no,
                'branch' => $r->branch,
                'customer' => $r->customer,
                'status' => 'inline',
                'reason' => 'Inline negative sale item',
                'subtotal' => $r->subtotal,
                'tax' => $r->tax,
                'total' => $r->total,
            ]);
        });

        $rows = $formalRows
            ->concat($inlineRows)
            ->sortBy('created_at', SORT_REGULAR, $f['direction'] === 'desc')
            ->values()
            ->all();

        return $this->report('Sales Return Detail', [
            ['key' => 'return_no', 'label' => 'Return No'], ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'invoice_no', 'label' => 'Invoice No'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'status', 'label' => 'Status'], ['key' => 'reason', 'label' => 'Reason'],
            ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $rows, $this->sumTotals($rows, ['subtotal', 'tax', 'total']), $f, $export);
    }

    private function purchaseSummary(array $f, bool $export): array
    {
        $date = $this->dateSql('p.created_at');
        $q = DB::table('purchases as p');
        $this->applyDateRange($q, 'p.created_at', $f);
        $this->applyPurchaseFilters($q, $f, 'p');
        $q->selectRaw("$date as date")
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(subtotal),0) as subtotal, COALESCE(SUM(discount),0) as discount, COALESCE(SUM(tax),0) as tax, COALESCE(SUM(total),0) as total')
            ->groupByRaw($date)->orderBy('date', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Purchase Summary', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'invoices', 'label' => 'Invoices'], ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'discount', 'label' => 'Discount'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'subtotal', 'discount', 'tax', 'total']), $f, $export);
    }

    private function purchaseDetail(array $f, bool $export): array
    {
        $paidSub = DB::table('vendor_payments')->select('purchase_id')->selectRaw('COALESCE(SUM(amount),0) as paid')->groupBy('purchase_id');
        $q = DB::table('purchases as p')
            ->leftJoin('vendors as v', 'v.id', '=', 'p.vendor_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->leftJoinSub($paidSub, 'vp', 'vp.purchase_id', '=', 'p.id');
        $this->applyDateRange($q, 'p.created_at', $f);
        $this->applyPurchaseFilters($q, $f, 'p');
        $this->applySearch($q, $f, ['p.invoice_no', 'v.company_name', 'v.first_name', 'v.phone']);
        $q->selectRaw('p.invoice_no, p.created_at, p.invoice_date, b.name as branch')
            ->selectRaw($this->vendorNameSql('v') . ' as vendor')
            ->selectRaw('p.status, p.receive_status, p.subtotal, p.discount, p.tax, p.total, COALESCE(vp.paid,0) as paid, (p.total - COALESCE(vp.paid,0)) as balance')
            ->orderBy('p.created_at', $f['direction']);
        $totals = $this->queryTotals($q, ['subtotal', 'discount', 'tax', 'total', 'paid', 'balance']);
        return $this->reportFromQuery('Purchase Detail', [
            ['key' => 'invoice_no', 'label' => 'Invoice No'], ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'invoice_date', 'label' => 'Invoice Date'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'status', 'label' => 'Payment Status'], ['key' => 'receive_status', 'label' => 'Receive Status'],
            ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'discount', 'label' => 'Discount'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'], ['key' => 'paid', 'label' => 'Paid'], ['key' => 'balance', 'label' => 'Balance'],
        ], $q, $totals, $f, $export);
    }

    private function purchaseByProduct(array $f, bool $export): array
    {
        $q = DB::table('purchase_items as pi')
            ->join('purchases as pur', 'pur.id', '=', 'pi.purchase_id')
            ->join('products as p', 'p.id', '=', 'pi.product_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id');
        $this->applyDateRange($q, 'pur.created_at', $f);
        $this->applyPurchaseFilters($q, $f, 'pur');
        $this->applyProductFilters($q, $f, 'p');
        $this->applySearch($q, $f, ['p.name', 'p.sku', 'p.barcode']);
        $q->selectRaw('p.id as product_id, p.sku, p.barcode, p.name as product, cat.name as category, br.name as brand')
            ->selectRaw('COALESCE(SUM(pi.quantity),0) as ordered_qty, COALESCE(SUM(pi.received_qty),0) as received_qty')
            ->selectRaw('COALESCE(SUM(pi.total),0) as purchase_total')
            ->groupBy('p.id', 'p.sku', 'p.barcode', 'p.name', 'cat.name', 'br.name')
            ->orderBy('purchase_total', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Purchase by Product', [
            ['key' => 'product_id', 'label' => 'Product ID'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'barcode', 'label' => 'Barcode'], ['key' => 'product', 'label' => 'Product'], ['key' => 'category', 'label' => 'Category'], ['key' => 'brand', 'label' => 'Brand'],
            ['key' => 'ordered_qty', 'label' => 'Ordered Qty'], ['key' => 'received_qty', 'label' => 'Received Qty'], ['key' => 'purchase_total', 'label' => 'Purchase Total'],
        ], $rows, $this->sumTotals($rows, ['ordered_qty', 'received_qty', 'purchase_total']), $f, $export);
    }

    private function purchaseGroupedByDimension(array $f, bool $export, string $dimension): array
    {
        $q = DB::table('purchases as p');
        $this->applyDateRange($q, 'p.created_at', $f);
        $this->applyPurchaseFilters($q, $f, 'p');
        if ($dimension === 'vendor') {
            $q->leftJoin('vendors as v', 'v.id', '=', 'p.vendor_id');
            $name = $this->vendorNameSql('v');
            $alias = 'vendor';
            $title = 'Purchase by Vendor';
        } else {
            $q->leftJoin('branches as b', 'b.id', '=', 'p.branch_id');
            $name = 'b.name';
            $alias = 'branch';
            $title = 'Purchase by Branch';
        }
        $q->selectRaw("COALESCE($name, 'Unassigned') as $alias")
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(p.subtotal),0) as subtotal, COALESCE(SUM(p.discount),0) as discount, COALESCE(SUM(p.tax),0) as tax, COALESCE(SUM(p.total),0) as total')
            ->groupByRaw($name)
            ->orderBy('total', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report($title, [
            ['key' => $alias, 'label' => ucfirst($alias)], ['key' => 'invoices', 'label' => 'Invoices'], ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'discount', 'label' => 'Discount'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'subtotal', 'discount', 'tax', 'total']), $f, $export);
    }

    private function vendorPaymentSummary(array $f, bool $export): array
    {
        $q = DB::table('vendor_payments as vp')
            ->leftJoin('vendors as v', 'v.id', '=', 'vp.vendor_id')
            ->leftJoin('branches as b', 'b.id', '=', 'vp.branch_id');
        $this->applyDateRange($q, 'vp.created_at', $f);
        $this->applyWhere($q, 'vp.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'vp.vendor_id', $f['vendor_id']);
        $this->applyWhere($q, 'vp.method', $f['method']);
        $q->selectRaw($this->vendorNameSql('v') . ' as vendor, b.name as branch, vp.method')
            ->selectRaw('COUNT(*) as payments, COALESCE(SUM(vp.amount),0) as amount')
            ->groupByRaw($this->vendorNameSql('v') . ', b.name, vp.method')
            ->orderBy('amount', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Vendor Payment Summary', [
            ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'method', 'label' => 'Method'], ['key' => 'payments', 'label' => 'Payments'], ['key' => 'amount', 'label' => 'Amount'],
        ], $rows, $this->sumTotals($rows, ['payments', 'amount']), $f, $export);
    }

    private function purchaseClaimSummary(array $f, bool $export): array
    {
        $date = $this->dateSql('pc.created_at');
        $q = DB::table('purchase_claims as pc');
        $this->applyDateRange($q, 'pc.created_at', $f);
        $this->applyWhere($q, 'pc.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'pc.vendor_id', $f['vendor_id']);
        $this->applyWhere($q, 'pc.status', $f['status']);
        $q->selectRaw("$date as date, pc.type, pc.status")
            ->selectRaw('COUNT(*) as claims, COALESCE(SUM(pc.subtotal),0) as subtotal, COALESCE(SUM(pc.tax),0) as tax, COALESCE(SUM(pc.total),0) as total')
            ->groupByRaw("$date, pc.type, pc.status")
            ->orderBy('date', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Purchase Claim Summary', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'type', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'], ['key' => 'claims', 'label' => 'Claims'], ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $rows, $this->sumTotals($rows, ['claims', 'subtotal', 'tax', 'total']), $f, $export);
    }

    private function purchaseClaimDetail(array $f, bool $export): array
    {
        $q = DB::table('purchase_claims as pc')
            ->leftJoin('purchases as p', 'p.id', '=', 'pc.purchase_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'pc.vendor_id')
            ->leftJoin('branches as b', 'b.id', '=', 'pc.branch_id');
        $this->applyDateRange($q, 'pc.created_at', $f);
        $this->applyWhere($q, 'pc.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'pc.vendor_id', $f['vendor_id']);
        $this->applyWhere($q, 'pc.status', $f['status']);
        $q->selectRaw('pc.claim_no, pc.created_at, p.invoice_no as purchase_invoice, b.name as branch')
            ->selectRaw($this->vendorNameSql('v') . ' as vendor')
            ->selectRaw('pc.type, pc.status, pc.reason, pc.subtotal, pc.tax, pc.total')
            ->orderBy('pc.created_at', $f['direction']);
        $totals = $this->queryTotals($q, ['subtotal', 'tax', 'total']);
        return $this->reportFromQuery('Purchase Claim Detail', [
            ['key' => 'claim_no', 'label' => 'Claim No'], ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'purchase_invoice', 'label' => 'Purchase Invoice'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'type', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'tax', 'label' => 'Tax'], ['key' => 'total', 'label' => 'Total'],
        ], $q, $totals, $f, $export);
    }

    private function currentStock(array $f, bool $export, bool $lowOnly): array
    {
        $q = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ps.branch_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->whereNull('p.deleted_at');
        $this->applyWhere($q, 'ps.branch_id', $f['branch_id']);
        $this->applyProductFilters($q, $f, 'p');
        if ($lowOnly) {
            $q->whereColumn('ps.quantity', '<=', 'p.reorder_level');
        }
        $this->applySearch($q, $f, ['p.name', 'p.sku', 'p.barcode']);
        $q->selectRaw('p.id as product_id, p.sku, p.barcode, p.name as product, cat.name as category, br.name as brand, b.name as branch')
            ->selectRaw('ps.quantity, p.reorder_level, COALESCE(ps.avg_cost, p.cost_price, 0) as avg_cost')
            ->selectRaw('(ps.quantity * COALESCE(ps.avg_cost, p.cost_price, 0)) as stock_value')
            ->orderBy($lowOnly ? 'ps.quantity' : 'p.name', $lowOnly ? 'asc' : 'asc');
        $totals = $this->queryTotals($q, ['quantity', 'stock_value']);
        return $this->reportFromQuery($lowOnly ? 'Low Stock / Reorder Report' : 'Current Stock', [
            ['key' => 'product_id', 'label' => 'Product ID'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'barcode', 'label' => 'Barcode'], ['key' => 'product', 'label' => 'Product'], ['key' => 'category', 'label' => 'Category'], ['key' => 'brand', 'label' => 'Brand'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'quantity', 'label' => 'Quantity'], ['key' => 'reorder_level', 'label' => 'Reorder Level'], ['key' => 'avg_cost', 'label' => 'Avg Cost'], ['key' => 'stock_value', 'label' => 'Stock Value'],
        ], $q, $totals, $f, $export);
    }

    private function stockValuation(array $f, bool $export): array
    {
        $q = DB::table('product_stocks as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ps.branch_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at');
        $this->applyWhere($q, 'ps.branch_id', $f['branch_id']);
        $this->applyProductFilters($q, $f, 'p');
        $q->selectRaw("COALESCE(b.name, 'Unassigned') as branch, COALESCE(cat.name, 'Uncategorized') as category")
            ->selectRaw('COUNT(DISTINCT p.id) as products')
            ->selectRaw('COALESCE(SUM(ps.quantity),0) as quantity')
            ->selectRaw('COALESCE(SUM(ps.quantity * COALESCE(ps.avg_cost, p.cost_price, 0)),0) as stock_value')
            ->groupBy('b.name', 'cat.name')
            ->orderBy('stock_value', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Stock Valuation', [
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'category', 'label' => 'Category'], ['key' => 'products', 'label' => 'Products'], ['key' => 'quantity', 'label' => 'Quantity'], ['key' => 'stock_value', 'label' => 'Stock Value'],
        ], $rows, $this->sumTotals($rows, ['products', 'quantity', 'stock_value']), $f, $export);
    }

    private function stockMovement(array $f, bool $export, bool $adjustmentsOnly): array
    {
        $q = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sm.branch_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id');
        $this->applyDateRange($q, 'sm.created_at', $f);
        $this->applyWhere($q, 'sm.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'sm.product_id', $f['product_id']);
        $this->applyWhere($q, 'sm.type', $adjustmentsOnly ? 'adjustment' : $f['stock_type']);
        $this->applyProductFilters($q, $f, 'p');
        $this->applySearch($q, $f, ['p.name', 'p.sku', 'p.barcode', 'sm.reference']);
        $q->selectRaw('sm.created_at, b.name as branch, p.sku, p.barcode, p.name as product, cat.name as category, sm.type, sm.quantity, sm.reference')
            ->orderBy('sm.created_at', $f['direction']);
        $totals = $this->queryTotals($q, ['quantity']);
        return $this->reportFromQuery($adjustmentsOnly ? 'Inventory Adjustment Report' : 'Stock Movement Ledger', [
            ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'barcode', 'label' => 'Barcode'], ['key' => 'product', 'label' => 'Product'], ['key' => 'category', 'label' => 'Category'], ['key' => 'type', 'label' => 'Type'], ['key' => 'quantity', 'label' => 'Quantity'], ['key' => 'reference', 'label' => 'Reference'],
        ], $q, $totals, $f, $export);
    }

    private function cashbook(array $f, bool $export): array
    {
        $q = DB::table('cash_transactions as ct')
            ->leftJoin('accounts as a', 'a.id', '=', 'ct.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ct.branch_id')
            ->whereNull('ct.deleted_at');
        $this->applyDateRange($q, 'ct.created_at', $f);
        $this->applyWhere($q, 'ct.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'ct.account_id', $f['account_id']);
        $this->applyWhere($q, 'ct.method', $f['method']);
        $this->applyWhere($q, 'ct.status', $f['status']);
        $q->selectRaw('ct.created_at, ct.txn_date, b.name as branch, a.name as account, ct.type, ct.method, ct.reference, ct.voucher_no, ct.note, ct.status')
            ->selectRaw("CASE WHEN ct.type IN ('receipt','transfer_in') THEN ct.amount ELSE 0 END as cash_in")
            ->selectRaw("CASE WHEN ct.type IN ('payment','expense','transfer_out') THEN ct.amount ELSE 0 END as cash_out")
            ->selectRaw('ct.amount')
            ->orderBy('ct.created_at', $f['direction']);
        $totals = $this->queryTotals($q, ['cash_in', 'cash_out', 'amount']);
        $totals['net_cash'] = $this->money(($totals['cash_in'] ?? 0) - ($totals['cash_out'] ?? 0));
        return $this->reportFromQuery('Cashbook', [
            ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'txn_date', 'label' => 'Txn Date'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'account', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'], ['key' => 'method', 'label' => 'Method'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'voucher_no', 'label' => 'Voucher No'], ['key' => 'cash_in', 'label' => 'Cash In'], ['key' => 'cash_out', 'label' => 'Cash Out'], ['key' => 'amount', 'label' => 'Amount'], ['key' => 'status', 'label' => 'Status'], ['key' => 'note', 'label' => 'Note'],
        ], $q, $totals, $f, $export);
    }

    private function daybook(array $f, bool $export): array
    {
        $q = DB::table('journal_entries as je')
            ->leftJoin('branches as b', 'b.id', '=', 'je.branch_id')
            ->leftJoin('journal_postings as jp', 'jp.journal_entry_id', '=', 'je.id');
        $this->applyDateRange($q, 'je.created_at', $f);
        $this->applyWhere($q, 'je.branch_id', $f['branch_id']);
        $q->selectRaw('je.id as journal_entry_id, je.entry_date, je.created_at, b.name as branch, je.memo, je.reference_type, je.reference_id')
            ->selectRaw('COALESCE(SUM(jp.debit),0) as debit, COALESCE(SUM(jp.credit),0) as credit')
            ->groupBy('je.id', 'je.entry_date', 'je.created_at', 'b.name', 'je.memo', 'je.reference_type', 'je.reference_id')
            ->orderBy('je.created_at', $f['direction']);
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Daybook', [
            ['key' => 'journal_entry_id', 'label' => 'Entry ID'], ['key' => 'entry_date', 'label' => 'Entry Date'], ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'reference_type', 'label' => 'Reference Type'], ['key' => 'reference_id', 'label' => 'Reference ID'], ['key' => 'debit', 'label' => 'Debit'], ['key' => 'credit', 'label' => 'Credit'],
        ], $rows, $this->sumTotals($rows, ['debit', 'credit']), $f, $export);
    }

    private function profitLoss(array $f, bool $export): array
    {
        $sales = DB::table('sales as s')->whereNull('s.deleted_at');
        $this->applyDateRange($sales, 's.created_at', $f);
        $this->applySalesFilters($sales, $f, 's');
        $s = $sales->selectRaw('COALESCE(SUM(total),0) as revenue, COALESCE(SUM(discount),0) as discounts, COALESCE(SUM(cogs),0) as cogs, COALESCE(SUM(gross_profit),0) as gross_profit')->first();

        $returns = DB::table('sale_returns as sr')->leftJoin('sales as s', 's.id', '=', 'sr.sale_id');
        $this->applyDateRange($returns, 'sr.created_at', $f);
        $this->applyReturnFilters($returns, $f, 'sr', 's');
        $formalReturnTotal = (float)($returns->selectRaw('COALESCE(SUM(sr.total),0) as total')->first()->total ?? 0);
        $inlineReturnTotal = $this->inlineReturnTotal($f, true);
        $returnTotal = $formalReturnTotal + $inlineReturnTotal;
        $grossSales = (float)($s->revenue ?? 0) + $inlineReturnTotal;
        $netSales = $grossSales - $returnTotal;
        $grossProfitAfterReturns = (float)($s->gross_profit ?? 0) - $formalReturnTotal;

        $expenses = DB::table('cash_transactions as ct')->whereNull('ct.deleted_at')->where('ct.type', 'expense');
        $this->applyDateRange($expenses, 'ct.created_at', $f);
        $this->applyWhere($expenses, 'ct.branch_id', $f['branch_id']);
        $expenseTotal = (float)($expenses->selectRaw('COALESCE(SUM(ct.amount),0) as total')->first()->total ?? 0);

        $rows = [
            ['section' => 'Income', 'description' => 'Gross Sales', 'amount' => $this->money($grossSales)],
            ['section' => 'Contra Income', 'description' => 'Sales Returns', 'amount' => $this->money(-$returnTotal)],
            ['section' => 'Net Income', 'description' => 'Net Sales', 'amount' => $this->money($netSales)],
            ['section' => 'Cost', 'description' => 'Cost of Goods Sold', 'amount' => $this->money(-($s->cogs ?? 0))],
            ['section' => 'Gross Profit', 'description' => 'Gross Profit After Returns', 'amount' => $this->money($grossProfitAfterReturns)],
            ['section' => 'Expense', 'description' => 'Operating Expenses', 'amount' => $this->money(-$expenseTotal)],
            ['section' => 'Net Profit', 'description' => 'Net Profit', 'amount' => $this->money($grossProfitAfterReturns - $expenseTotal)],
        ];
        return $this->report('Profit & Loss', [
            ['key' => 'section', 'label' => 'Section'], ['key' => 'description', 'label' => 'Description'], ['key' => 'amount', 'label' => 'Amount'],
        ], $rows, [
            'gross_sales' => $this->money($grossSales),
            'returns' => $this->money($returnTotal),
            'cogs' => $this->money($s->cogs ?? 0),
            'expenses' => $this->money($expenseTotal),
            'net_profit' => $this->money($grossProfitAfterReturns - $expenseTotal),
        ], $f, $export);
    }

    private function customerReceivables(array $f, bool $export): array
    {
        $customerTypes = ['customer', 'App\Models\Customer'];
        $effectiveDate = 'COALESCE(jp.created_at, je.entry_date, je.created_at)';

        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->leftJoin('customers as c', 'c.id', '=', 'jp.party_id')
            ->whereIn('jp.party_type', $customerTypes);

        // Receivables must come from ledger balance, not sales history.
        // Financial-year closing removes closed-branch sales but keeps opening
        // journal postings, so applying the "from" date would hide those
        // opening balances. Use branch + optional as-of/to date only.
        $this->applyWhere($q, 'je.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'jp.party_id', $f['customer_id']);
        if ($f['to']) {
            $q->whereRaw("$effectiveDate <= ?", [$f['to']->toDateTimeString()]);
        }
        $this->applySearch($q, $f, ['c.first_name', 'c.last_name', 'c.phone']);

        $q->selectRaw('c.id as customer_id')
            ->selectRaw($this->nameSql('c.first_name', 'c.last_name') . ' as customer')
            ->selectRaw('c.phone')
            ->selectRaw('COUNT(DISTINCT je.id) as invoices')
            ->selectRaw('COALESCE(SUM(CASE WHEN jp.debit > 0 THEN jp.debit ELSE 0 END),0) as total_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END),0) as paid')
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit),0) as balance')
            ->selectRaw("MAX($effectiveDate) as last_activity_at")
            ->groupBy('c.id', 'c.first_name', 'c.last_name', 'c.phone')
            ->havingRaw('ABS(COALESCE(SUM(jp.debit - jp.credit),0)) > 0.00001')
            ->orderBy('balance', 'desc');

        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();

        return $this->report('Customer Receivables / AR Aging Base', [
            ['key' => 'customer_id', 'label' => 'Customer ID'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'invoices', 'label' => 'Ledger Entries'],
            ['key' => 'total_sales', 'label' => 'Debit'],
            ['key' => 'paid', 'label' => 'Credit'],
            ['key' => 'balance', 'label' => 'Balance'],
            ['key' => 'last_activity_at', 'label' => 'Last Activity'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'total_sales', 'paid', 'balance']), $f, $export);
    }

    private function vendorPayables(array $f, bool $export): array
    {
        $vendorTypes = ['vendor', 'App\Models\Vendor'];
        $effectiveDate = 'COALESCE(jp.created_at, je.entry_date, je.created_at)';

        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'jp.party_id')
            ->whereIn('jp.party_type', $vendorTypes);

        // Payables must come from ledger balance, not purchase history.
        // After closing a branch, old purchases are removed for that branch but
        // AP opening balances remain in journal_postings with vendor party_id.
        $this->applyWhere($q, 'je.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'jp.party_id', $f['vendor_id']);
        if ($f['to']) {
            $q->whereRaw("$effectiveDate <= ?", [$f['to']->toDateTimeString()]);
        }
        $this->applySearch($q, $f, ['v.company_name', 'v.first_name', 'v.last_name', 'v.phone']);

        $q->selectRaw('v.id as vendor_id')
            ->selectRaw($this->vendorNameSql('v') . ' as vendor')
            ->selectRaw('v.phone')
            ->selectRaw('COUNT(DISTINCT je.id) as invoices')
            ->selectRaw('COALESCE(SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END),0) as purchase_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN jp.debit > 0 THEN jp.debit ELSE 0 END),0) as paid')
            ->selectRaw('COALESCE(SUM(jp.credit - jp.debit),0) as balance')
            ->selectRaw("MAX($effectiveDate) as last_activity_at")
            ->groupBy('v.id', 'v.company_name', 'v.first_name', 'v.last_name', 'v.phone')
            ->havingRaw('ABS(COALESCE(SUM(jp.credit - jp.debit),0)) > 0.00001')
            ->orderBy('balance', 'desc');

        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();

        return $this->report('Vendor Payables / AP Aging Base', [
            ['key' => 'vendor_id', 'label' => 'Vendor ID'],
            ['key' => 'vendor', 'label' => 'Vendor'],
            ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'invoices', 'label' => 'Ledger Entries'],
            ['key' => 'purchase_total', 'label' => 'Credit'],
            ['key' => 'paid', 'label' => 'Debit'],
            ['key' => 'balance', 'label' => 'Balance'],
            ['key' => 'last_activity_at', 'label' => 'Last Activity'],
        ], $rows, $this->sumTotals($rows, ['invoices', 'purchase_total', 'paid', 'balance']), $f, $export);
    }

    private function trialBalance(array $f, bool $export): array
    {
        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jp.account_id')
            ->leftJoin('account_types as at', 'at.id', '=', 'a.account_type_id');
        $this->applyDateRange($q, 'je.created_at', $f);
        $this->applyWhere($q, 'je.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'a.id', $f['account_id']);
        $q->selectRaw('a.code, a.name as account, at.code as account_type')
            ->selectRaw('COALESCE(SUM(jp.debit),0) as debit, COALESCE(SUM(jp.credit),0) as credit, COALESCE(SUM(jp.debit - jp.credit),0) as balance')
            ->groupBy('a.code', 'a.name', 'at.code')
            ->orderBy('a.code');
        $rows = $q->get()->map(fn($r) => $this->roundRow((array)$r))->all();
        return $this->report('Trial Balance', [
            ['key' => 'code', 'label' => 'Account Code'], ['key' => 'account', 'label' => 'Account'], ['key' => 'account_type', 'label' => 'Type'], ['key' => 'debit', 'label' => 'Debit'], ['key' => 'credit', 'label' => 'Credit'], ['key' => 'balance', 'label' => 'Balance'],
        ], $rows, $this->sumTotals($rows, ['debit', 'credit', 'balance']), $f, $export);
    }

    private function ledgerDetail(array $f, bool $export): array
    {
        $q = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jp.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'je.branch_id');
        $this->applyDateRange($q, 'je.created_at', $f);
        $this->applyWhere($q, 'je.branch_id', $f['branch_id']);
        $this->applyWhere($q, 'a.id', $f['account_id']);
        if ($f['party_type'] && $f['party_id']) {
            $partyClass = $f['party_type'] === 'vendor' ? 'App\\Models\\Vendor' : 'App\\Models\\Customer';
            $q->where('jp.party_type', $partyClass)->where('jp.party_id', $f['party_id']);
        }
        $q->selectRaw('je.entry_date, je.created_at, b.name as branch, a.code, a.name as account, je.memo, je.reference_type, je.reference_id, jp.party_type, jp.party_id, jp.debit, jp.credit')
            ->orderBy('je.created_at', $f['direction']);
        $totals = $this->queryTotals($q, ['debit', 'credit']);
        $totals['balance'] = $this->money(($totals['debit'] ?? 0) - ($totals['credit'] ?? 0));
        return $this->reportFromQuery('Ledger Detail', [
            ['key' => 'entry_date', 'label' => 'Entry Date'], ['key' => 'created_at', 'label' => 'Created At'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'code', 'label' => 'Account Code'], ['key' => 'account', 'label' => 'Account'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'reference_type', 'label' => 'Reference Type'], ['key' => 'reference_id', 'label' => 'Reference ID'], ['key' => 'party_type', 'label' => 'Party Type'], ['key' => 'party_id', 'label' => 'Party ID'], ['key' => 'debit', 'label' => 'Debit'], ['key' => 'credit', 'label' => 'Credit'],
        ], $q, $totals, $f, $export);
    }

    private function withoutBranchExposure(array $report): array
    {
        $hiddenKeys = ['branch', 'branch_id', 'branch_name'];

        if (isset($report['columns']) && is_array($report['columns'])) {
            $report['columns'] = array_values(array_filter(
                $report['columns'],
                fn ($column) => !in_array((string) ($column['key'] ?? ''), $hiddenKeys, true)
            ));
        }

        if (isset($report['rows']) && is_array($report['rows'])) {
            $report['rows'] = array_map(function ($row) use ($hiddenKeys) {
                if (is_array($row)) {
                    foreach ($hiddenKeys as $key) {
                        unset($row[$key]);
                    }
                }

                return $row;
            }, $report['rows']);
        }

        return $report;
    }

    private function reportFromQuery(string $title, array $columns, Builder $query, array $totals, array $filters, bool $export): array
    {
        if ($export) {
            $rows = $query->get()->map(fn($r) => $this->roundRow((array)$r))->all();
            return $this->baseReport($title, $columns, $rows, $totals, null);
        }

        $paginator = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
        return $this->baseReport($title, $columns, $this->rowsFromPaginator($paginator), $totals, $this->pagination($paginator));
    }

    private function report(string $title, array $columns, array $rows, array $totals, array $filters, bool $export): array
    {
        $pagination = null;
        if (!$export) {
            [$rows, $pagination] = $this->paginateArray($rows, $filters['page'], $filters['per_page']);
        }
        return $this->baseReport($title, $columns, $rows, $totals, $pagination);
    }

    private function baseReport(string $title, array $columns, array $rows, array $totals, ?array $pagination): array
    {
        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'pagination' => $pagination,
        ];
    }

    private function queryTotals(Builder $query, array $keys): array
    {
        $rows = (clone $query)->get();
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = $this->money($rows->sum(fn($r) => (float)($r->{$key} ?? 0)));
        }
        return $totals;
    }

    private function sumTotals(array $rows, array $keys): array
    {
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = $this->money(collect($rows)->sum(fn($row) => (float)($row[$key] ?? 0)));
        }
        return $totals;
    }

    private function rowsFromPaginator(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(fn($r) => $this->roundRow((array)$r))->values()->all();
    }

    private function pagination(LengthAwarePaginator $p): array
    {
        return [
            'current_page' => $p->currentPage(),
            'per_page' => $p->perPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
        ];
    }

    private function paginateArray(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $last = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $last);
        return [array_slice($rows, ($page - 1) * $perPage, $perPage), [
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $last,
            'total' => $total,
        ]];
    }

    private function inlineReturnItemsBase(array $f, bool $respectSaleStatus = false): Builder
    {
        $q = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereNull('s.deleted_at')
            ->where(function ($negative) {
                $negative->where('si.quantity', '<', 0)
                    ->orWhere('si.total', '<', 0);
            });

        $this->applyDateRange($q, 's.created_at', $f);
        $this->applyWhere($q, 's.branch_id', $f['branch_id']);
        $this->applyWhere($q, 's.customer_id', $f['customer_id']);
        $this->applyWhere($q, 's.vendor_id', $f['vendor_id']);
        $this->applyWhere($q, 's.salesman_id', $f['salesman_id']);
        $this->applyWhere($q, 's.delivery_boy_id', $f['delivery_boy_id']);
        $this->applyWhere($q, 's.created_by', $f['created_by']);
        $this->applyWhere($q, 's.sale_type', $f['sale_type']);

        if ($respectSaleStatus) {
            $this->applyWhere($q, 's.status', $f['status']);
        }

        return $q;
    }

    private function inlineReturnTotal(array $f, bool $respectSaleStatus = false): float
    {
        $row = $this->inlineReturnItemsBase($f, $respectSaleStatus)
            ->selectRaw('COALESCE(SUM(ABS(si.total)),0) as total')
            ->first();

        return (float)($row->total ?? 0);
    }

    private function applyDateRange(Builder $q, string $column, array $f): void
    {
        if ($f['from']) {
            $q->where($column, '>=', $f['from']->toDateTimeString());
        }
        if ($f['to']) {
            $q->where($column, '<=', $f['to']->toDateTimeString());
        }
    }

    private function applySalesFilters(Builder $q, array $f, string $alias = 's'): void
    {
        $this->applyWhere($q, "$alias.branch_id", $f['branch_id']);
        $this->applyWhere($q, "$alias.customer_id", $f['customer_id']);
        $this->applyWhere($q, "$alias.vendor_id", $f['vendor_id']);
        $this->applyWhere($q, "$alias.salesman_id", $f['salesman_id']);
        $this->applyWhere($q, "$alias.delivery_boy_id", $f['delivery_boy_id']);
        $this->applyWhere($q, "$alias.created_by", $f['created_by']);
        $this->applyWhere($q, "$alias.status", $f['status']);
        $this->applyWhere($q, "$alias.sale_type", $f['sale_type']);
    }

    private function applyPurchaseFilters(Builder $q, array $f, string $alias = 'p'): void
    {
        $this->applyWhere($q, "$alias.branch_id", $f['branch_id']);
        $this->applyWhere($q, "$alias.vendor_id", $f['vendor_id']);
        $this->applyWhere($q, "$alias.created_by", $f['created_by']);
        $this->applyWhere($q, "$alias.status", $f['status']);
    }

    private function applyReturnFilters(Builder $q, array $f, string $returnAlias = 'sr', string $saleAlias = 's'): void
    {
        $this->applyWhere($q, "$returnAlias.branch_id", $f['branch_id']);
        $this->applyWhere($q, "$returnAlias.customer_id", $f['customer_id']);
        $this->applyWhere($q, "$returnAlias.vendor_id", $f['vendor_id']);
        $this->applyWhere($q, "$returnAlias.status", $f['status']);
        $this->applyWhere($q, "$saleAlias.salesman_id", $f['salesman_id']);
    }

    private function applyProductFilters(Builder $q, array $f, string $alias = 'p'): void
    {
        $this->applyWhere($q, "$alias.id", $f['product_id']);
        $this->applyWhere($q, "$alias.category_id", $f['category_id']);
        $this->applyWhere($q, "$alias.brand_id", $f['brand_id']);
        $this->applyWhere($q, "$alias.vendor_id", $f['vendor_id']);
    }

    private function applyWhere(Builder $q, string $column, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $q->where($column, $value);
        }
    }

    private function applySearch(Builder $q, array $f, array $columns): void
    {
        if (!($f['search'] ?? null)) {
            return;
        }
        $search = '%' . $f['search'] . '%';
        $q->where(function ($sub) use ($columns, $search) {
            foreach ($columns as $column) {
                $sub->orWhere($column, 'like', $search);
            }
        });
    }

    private function sortColumn(array $f, array $allowed, string $default): string
    {
        $requested = $f['sort_by'] ?? null;
        return $requested && isset($allowed[$requested]) ? $allowed[$requested] : $default;
    }

    private function publicFilters(array $f): array
    {
        $out = $f;
        $out['from'] = $f['from']?->toDateTimeString();
        $out['to'] = $f['to']?->toDateTimeString();
        unset($out['branch_id']);
        return $out;
    }

    private function parseFrom(mixed $value): ?Carbon
    {
        if (!$value) return null;
        $carbon = Carbon::parse($value);
        return $this->isDateOnly((string)$value) ? $carbon->startOfDay() : $carbon;
    }

    private function parseTo(mixed $value): ?Carbon
    {
        if (!$value) return null;
        $carbon = Carbon::parse($value);
        return $this->isDateOnly((string)$value) ? $carbon->endOfDay() : $carbon;
    }

    private function isDateOnly(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value));
    }

    private function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int)$value;
    }

    private function dateSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "date($column)" : "DATE($column)";
    }

    private function hourSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "strftime('%H', $column)" : "LPAD(HOUR($column), 2, '0')";
    }

    private function nameSql(string $first, string $last): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "TRIM(COALESCE($first, '') || ' ' || COALESCE($last, ''))";
        }
        return "TRIM(CONCAT(COALESCE($first, ''), ' ', COALESCE($last, '')))";
    }

    private function vendorNameSql(string $alias): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "TRIM(COALESCE($alias.company_name, '') || ' ' || COALESCE($alias.first_name, '') || ' ' || COALESCE($alias.last_name, ''))";
        }
        return "TRIM(CONCAT(COALESCE($alias.company_name, ''), ' ', COALESCE($alias.first_name, ''), ' ', COALESCE($alias.last_name, '')))";
    }

    private function money(mixed $value): float
    {
        return round((float)$value, 2);
    }

    private function roundRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_numeric($value) && !str_ends_with((string)$key, '_id') && !in_array($key, ['id', 'product_id', 'customer_id', 'vendor_id', 'delivery_boy_id', 'journal_entry_id'], true)) {
                $row[$key] = $this->money($value);
            }
        }
        return $row;
    }

    private function salesSummaryColumns(): array
    {
        return [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'invoices', 'label' => 'Invoices'],
            ['key' => 'subtotal', 'label' => 'Subtotal'], ['key' => 'discount', 'label' => 'Discount'],
            ['key' => 'tax', 'label' => 'Tax'], ['key' => 'delivery', 'label' => 'Delivery'],
            ['key' => 'gross_sales', 'label' => 'Gross Sales'], ['key' => 'returns', 'label' => 'Returns'],
            ['key' => 'net_sales', 'label' => 'Net Sales'], ['key' => 'cogs', 'label' => 'COGS'],
            ['key' => 'gross_profit', 'label' => 'Gross Profit'],
        ];
    }
}
