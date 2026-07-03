<?php

namespace App\Services\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Vendor;
use App\Services\Reports\NativeXlsxExporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProductImportExportService
{
    /**
     * Column key => human label, in the exact order used by export files
     * and the import template. Category/brand/vendor are matched by name
     * (not numeric id) so the file is human-editable.
     */
    public const COLUMNS = [
        'sku' => 'SKU',
        'barcode' => 'Barcode',
        'name' => 'Name',
        'description' => 'Description',
        'category' => 'Category',
        'brand' => 'Brand',
        'vendor' => 'Vendor',
        'price' => 'Price',
        'cost_price' => 'Cost Price',
        'wholesale_price' => 'Wholesale Price',
        'stock_qty' => 'Stock Qty',
        'reorder_level' => 'Reorder Level',
        'track_inventory' => 'Track Inventory',
        'tax_rate' => 'Tax Rate',
        'tax_inclusive' => 'Tax Inclusive',
        'discount' => 'Discount',
        'is_active' => 'Is Active',
    ];

    public function __construct(
        private NativeXlsxExporter $xlsxExporter,
        private NativeXlsxReader $xlsxReader,
    ) {
    }

    /* -----------------------------------------------------------------
     | Export
     |-----------------------------------------------------------------*/

    /**
     * @param Collection<int, Product> $products
     * @return array<int, array<string, mixed>>
     */
    public function rowsForExport(Collection $products): array
    {
        return $products->map(function (Product $product) {
            return [
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'vendor' => $this->vendorLabel($product->vendor),
                'price' => $product->price,
                'cost_price' => $product->cost_price,
                'wholesale_price' => $product->wholesale_price,
                'stock_qty' => $product->stock_qty,
                'reorder_level' => $product->reorder_level,
                'track_inventory' => $product->track_inventory ? 1 : 0,
                'tax_rate' => $product->tax_rate,
                'tax_inclusive' => $product->tax_inclusive ? 1 : 0,
                'discount' => $product->discount,
                'is_active' => $product->is_active ? 1 : 0,
            ];
        })->values()->all();
    }

    public function exportToXlsx(array $rows, string $title = 'Products Export'): string
    {
        $columns = collect(self::COLUMNS)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();

        return $this->xlsxExporter->export([
            'key' => 'products-export',
            'title' => $title,
            'generated_at' => now()->toDateTimeString(),
            'columns' => $columns,
            'rows' => $rows,
            'filters' => [],
            'totals' => [],
        ]);
    }

    public function exportToCsv(array $rows): string
    {
        return $this->toCsv($rows);
    }

    /**
     * A single example row used to seed the downloadable import template.
     */
    public function templateRows(): array
    {
        return [[
            'sku' => 'SKU-0001',
            'barcode' => '0123456789012',
            'name' => 'Sample Product',
            'description' => 'Optional description',
            'category' => 'Beverages',
            'brand' => 'Acme',
            'vendor' => 'Acme Distributors',
            'price' => 100,
            'cost_price' => 70,
            'wholesale_price' => 85,
            'stock_qty' => 0,
            'reorder_level' => 5,
            'track_inventory' => 1,
            'tax_rate' => 0,
            'tax_inclusive' => 1,
            'discount' => 0,
            'is_active' => 1,
        ]];
    }

    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_values(self::COLUMNS));

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys(self::COLUMNS) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    private function vendorLabel(?Vendor $vendor): ?string
    {
        if (!$vendor) {
            return null;
        }

        if (!empty($vendor->company_name)) {
            return $vendor->company_name;
        }

        $name = trim(($vendor->first_name ?? '') . ' ' . ($vendor->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    /* -----------------------------------------------------------------
     | Import
     |-----------------------------------------------------------------*/

    /**
     * Parses an uploaded CSV or XLSX file into rows keyed by our column
     * keys (sku, barcode, name, ...), tolerating reordered/renamed-ish
     * headers via a small alias table.
     *
     * @return array<int, array<string, string>>
     */
    public function parseUpload(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            $rawRows = $this->xlsxReader->read($file->getRealPath());
        } elseif (in_array($extension, ['csv', 'txt'], true)) {
            $rawRows = $this->readCsv($file->getRealPath());
        } else {
            throw new RuntimeException('Unsupported file type. Please upload a .csv or .xlsx file.');
        }

        if (empty($rawRows)) {
            return [];
        }

        $header = array_map(fn ($h) => $this->normalizeHeader((string) $h), array_shift($rawRows));
        $keyMap = $this->mapHeaderToKeys($header);

        if (empty($keyMap)) {
            throw new RuntimeException('The file header did not match any expected column names. Download the template and use it as a starting point.');
        }

        $rows = [];
        foreach ($rawRows as $rawRow) {
            if (!$this->hasAnyValue($rawRow)) {
                continue; // skip fully blank rows
            }

            $row = [];
            foreach ($keyMap as $columnIndex => $key) {
                $row[$key] = trim((string) ($rawRow[$columnIndex] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Imports already-parsed rows for the given branch and returns a
     * per-row report rather than failing the whole batch on one bad row.
     *
     * @return array{total_rows: int, created: int, updated: int, failed: int, errors: array<int, array{row: int, sku: ?string, message: string}>}
     */
    public function importRows(array $rows, int $branchId): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for 0-index, +1 for the header row

            try {
                $outcome = DB::transaction(fn () => $this->importRow($row, $branchId));
                if ($outcome === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'sku' => $row['sku'] !== '' ? ($row['sku'] ?? null) : null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_rows' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        // Strip a UTF-8 BOM if present (common when files are saved from Excel on Windows).
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $header) ?? $header));
    }

    private function mapHeaderToKeys(array $header): array
    {
        $aliases = [
            'sku' => 'sku',
            'barcode' => 'barcode',
            'name' => 'name',
            'product name' => 'name',
            'description' => 'description',
            'category' => 'category',
            'brand' => 'brand',
            'vendor' => 'vendor',
            'price' => 'price',
            'selling price' => 'price',
            'cost price' => 'cost_price',
            'cost_price' => 'cost_price',
            'wholesale price' => 'wholesale_price',
            'wholesale_price' => 'wholesale_price',
            'stock qty' => 'stock_qty',
            'stock_qty' => 'stock_qty',
            'reorder level' => 'reorder_level',
            'reorder_level' => 'reorder_level',
            'track inventory' => 'track_inventory',
            'track_inventory' => 'track_inventory',
            'tax rate' => 'tax_rate',
            'tax_rate' => 'tax_rate',
            'tax inclusive' => 'tax_inclusive',
            'tax_inclusive' => 'tax_inclusive',
            'discount' => 'discount',
            'is active' => 'is_active',
            'is_active' => 'is_active',
        ];

        $map = [];
        foreach ($header as $index => $label) {
            if (isset($aliases[$label])) {
                $map[$index] = $aliases[$label];
            }
        }

        return $map;
    }

    private function hasAnyValue(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function importRow(array $row, int $branchId): string
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $priceRaw = trim((string) ($row['price'] ?? ''));

        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        if ($priceRaw === '' || !is_numeric($priceRaw)) {
            throw new RuntimeException('Price is required and must be numeric.');
        }

        $existing = Product::query()
            ->where('branch_id', $branchId)
            ->where('sku', $sku)
            ->first();

        $categoryId = $this->resolveCategoryId($row['category'] ?? null);
        $brandId = $this->resolveBrandId($row['brand'] ?? null);
        $vendorId = $this->resolveVendorId($row['vendor'] ?? null, $branchId);

        $data = [
            'branch_id' => $branchId,
            'sku' => $sku,
            'barcode' => $this->blankToNull($row['barcode'] ?? null),
            'name' => $name,
            'description' => $this->blankToNull($row['description'] ?? null),
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'vendor_id' => $vendorId,
            'price' => (float) $priceRaw,
            'cost_price' => $this->numericOrDefault($row['cost_price'] ?? null, null),
            'wholesale_price' => $this->numericOrDefault($row['wholesale_price'] ?? null, null),
            'reorder_level' => (int) $this->numericOrDefault($row['reorder_level'] ?? null, 0),
            'track_inventory' => $this->boolOrDefault($row['track_inventory'] ?? null, true),
            'tax_rate' => $this->numericOrDefault($row['tax_rate'] ?? null, 0),
            'tax_inclusive' => $this->boolOrDefault($row['tax_inclusive'] ?? null, true),
            'discount' => $this->numericOrDefault($row['discount'] ?? null, null),
            'is_active' => $this->boolOrDefault($row['is_active'] ?? null, true),
        ];

        $stockQtyProvided = trim((string) ($row['stock_qty'] ?? '')) !== '';
        $stockQty = $stockQtyProvided ? (int) $this->numericOrDefault($row['stock_qty'] ?? null, 0) : null;
        $data['stock_qty'] = $stockQty ?? ($existing ? $existing->stock_qty : 0);

        if ($existing) {
            $existing->update($data);

            if ($stockQtyProvided) {
                // Explicit stock figures in the file update the branch's live
                // stock count. Blank stock_qty leaves current stock untouched
                // so re-importing a catalog update doesn't silently zero out
                // inventory.
                ProductStock::updateOrCreate(
                    ['product_id' => $existing->id, 'branch_id' => $branchId],
                    ['quantity' => $stockQty, 'avg_cost' => $data['cost_price'] ?? 0]
                );
            }

            return 'updated';
        }

        $product = Product::create($data);
        ProductStock::updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['quantity' => $stockQty ?? 0, 'avg_cost' => $data['cost_price'] ?? 0]
        );

        return 'created';
    }

    private function resolveCategoryId(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $category = Category::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if (!$category) {
            $category = Category::create(['name' => $name]);
        }

        return $category->id;
    }

    private function resolveBrandId(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $brand = Brand::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if (!$brand) {
            $brand = Brand::create(['name' => $name]);
        }

        return $brand->id;
    }

    private function resolveVendorId(?string $name, int $branchId): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $vendor = Vendor::query()
            ->whereRaw('LOWER(company_name) = ?', [strtolower($name)])
            ->first();

        if (!$vendor) {
            throw new RuntimeException("Vendor \"{$name}\" was not found. Create the vendor first, fix the spelling, or leave the column blank.");
        }

        if (!empty($vendor->branch_id) && (int) $vendor->branch_id !== $branchId) {
            throw new RuntimeException("Vendor \"{$name}\" belongs to a different branch.");
        }

        return $vendor->id;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function numericOrDefault(?string $value, $default)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }

        return is_numeric($value) ? (float) $value : $default;
    }

    private function boolOrDefault(?string $value, bool $default): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'y'], true);
    }
}
