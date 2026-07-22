<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial add-on entitlements are deliberately separate from operational
 * branch feature flags and employee permissions.
 *
 * Missing rows are inactive (fail closed). Existing branches that already
 * have barcode printing configured are backfilled as entitled so deploying
 * this commercial gate does not silently break a working production printer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('addon_key', 100);
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'addon_key'], 'branch_addons_branch_key_unique');
            $table->index(['addon_key', 'is_active'], 'branch_addons_key_active_idx');
        });

        Schema::create('branch_addon_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('addon_key', 100)->index();
            $table->boolean('old_active');
            $table->boolean('new_active');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // No foreign keys: commercial audit history must survive deleted
            // branches and users.
        });

        $branchIds = collect();
        if (Schema::hasTable('printer_settings')
            && Schema::hasColumn('printer_settings', 'barcode_print_enabled')) {
            $globalEnabled = DB::table('printer_settings')
                ->whereNull('branch_id')
                ->where('barcode_print_enabled', true)
                ->exists();

            $branchIds = $globalEnabled
                ? DB::table('branches')->whereNull('deleted_at')->pluck('id')
                : DB::table('printer_settings')
                    ->whereNotNull('branch_id')
                    ->where('barcode_print_enabled', true)
                    ->distinct()
                    ->pluck('branch_id');
        }

        if ($branchIds->isNotEmpty()) {
            $now = now();
            DB::table('branch_addons')->insert($branchIds->map(fn ($branchId) => [
                'branch_id' => (int) $branchId,
                'addon_key' => 'barcode_labels',
                'is_active' => true,
                'activated_at' => $now,
                'deactivated_at' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            DB::table('branch_addon_audits')->insert($branchIds->map(fn ($branchId) => [
                'branch_id' => (int) $branchId,
                'addon_key' => 'barcode_labels',
                'old_active' => false,
                'new_active' => true,
                'changed_by' => null,
                'reason' => 'Migrated existing barcode printer configuration.',
                'metadata' => json_encode(['source' => 'migration']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_addon_audits');
        Schema::dropIfExists('branch_addons');
    }
};
