<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductImportExportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main Branch', 'is_active' => true]);

        foreach (['view-products', 'manage-products'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->user->givePermissionTo(['view-products', 'manage-products']);

        $this->actingAs($this->user, 'sanctum');
    }

    private function uploadCsv(string $content, string $filename = 'products.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_test_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    public function test_happy_path_import_creates_products_and_auto_creates_lookups(): void
    {
        $csv = "SKU,Barcode,Name,Description,Category,Brand,Vendor,Price,Cost Price,Wholesale Price,Stock Qty,Reorder Level,Track Inventory,Tax Rate,Tax Inclusive,Discount,Is Active\n"
            . "SKU-100,1111111111111,Cola 500ml,,Beverages,Acme,,120,90,100,10,2,1,0,1,0,1\n"
            . "SKU-101,2222222222222,Chips 100g,,Snacks,Acme,,80,55,,5,1,1,0,1,0,1\n";

        $response = $this->post('/api/v1/products/import', [
            'file' => $this->uploadCsv($csv),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_rows', 2);
        $response->assertJsonPath('data.created', 2);
        $response->assertJsonPath('data.updated', 0);
        $response->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-100',
            'branch_id' => $this->branch->id,
            'name' => 'Cola 500ml',
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-101',
            'branch_id' => $this->branch->id,
        ]);

        // Category/brand columns are matched by name and auto-created when missing.
        $this->assertDatabaseHas('categories', ['name' => 'Beverages']);
        $this->assertDatabaseHas('categories', ['name' => 'Snacks']);
        $this->assertDatabaseHas('brands', ['name' => 'Acme']);

        $product = Product::where('sku', 'SKU-100')->first();
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 10,
        ]);
    }

    public function test_malformed_row_is_reported_without_failing_the_whole_batch(): void
    {
        $csv = "SKU,Name,Price\n"
            . "SKU-200,Good Product,50\n"
            . "SKU-201,Missing Price,\n"; // blank price -> invalid row

        $response = $this->post('/api/v1/products/import', [
            'file' => $this->uploadCsv($csv),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_rows', 2);
        $response->assertJsonPath('data.created', 1);
        $response->assertJsonPath('data.failed', 1);
        $response->assertJsonPath('data.errors.0.row', 3);
        $response->assertJsonPath('data.errors.0.sku', 'SKU-201');

        $this->assertDatabaseHas('products', ['sku' => 'SKU-200']);
        $this->assertDatabaseMissing('products', ['sku' => 'SKU-201']);
    }

    public function test_duplicate_sku_within_branch_updates_the_existing_product_instead_of_erroring(): void
    {
        Product::create([
            'branch_id' => $this->branch->id,
            'sku' => 'SKU-300',
            'name' => 'Old Name',
            'price' => 10,
            'is_active' => true,
        ]);

        $csv = "SKU,Name,Price\nSKU-300,New Name,25\n";

        $response = $this->post('/api/v1/products/import', [
            'file' => $this->uploadCsv($csv),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.created', 0);
        $response->assertJsonPath('data.updated', 1);
        $response->assertJsonPath('data.failed', 0);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-300',
            'branch_id' => $this->branch->id,
            'name' => 'New Name',
            'price' => 25,
        ]);
    }

    public function test_export_streams_csv_for_the_active_branch_only(): void
    {
        Product::create([
            'branch_id' => $this->branch->id,
            'sku' => 'SKU-400',
            'name' => 'Exportable Product',
            'price' => 42,
            'is_active' => true,
        ]);

        $otherBranch = Branch::create(['name' => 'Other Branch', 'is_active' => true]);
        Product::create([
            'branch_id' => $otherBranch->id,
            'sku' => 'SKU-999',
            'name' => 'Other Branch Product',
            'price' => 1,
            'is_active' => true,
        ]);

        $response = $this->get('/api/v1/products/export?format=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv');

        $csv = $response->getContent();
        $this->assertStringContainsString('SKU-400', $csv);
        $this->assertStringContainsString('Exportable Product', $csv);
        $this->assertStringNotContainsString('SKU-999', $csv);
    }
}
