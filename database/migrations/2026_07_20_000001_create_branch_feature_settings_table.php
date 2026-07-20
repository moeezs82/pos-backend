<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-wise feature flag table.
 *
 * One row per branch, written only when settings differ from defaults.
 * Missing rows resolve to all features enabled (fail-open / backward-safe).
 *
 * Do NOT modify existing rows to disable features — always write via
 * BranchFeatureService which validates, audits, and enforces in one transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_feature_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')
                ->unique()
                ->constrained('branches')
                ->onDelete('cascade');

            // ── Feature flags (default true = enabled for all branches) ────
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('sale_vendor_enabled')->default(true);

            // Actor who last changed this row.
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();

            $table->index('branch_id', 'bfs_branch_idx');
        });

        // Append-only audit log for every feature-flag change.
        Schema::create('branch_feature_audits', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('changed_by')->nullable();

            // Serialised old/new values as JSON so new flags can be added
            // without a migration touching this table.
            $table->json('old_values');
            $table->json('new_values');

            $table->timestamp('changed_at')->useCurrent();

            $table->index('branch_id', 'bfa_branch_idx');
            $table->index('changed_at', 'bfa_changed_at_idx');

            // No FK constraints so historical audit rows survive if a branch
            // or user is soft-deleted or hard-deleted.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_feature_audits');
        Schema::dropIfExists('branch_feature_settings');
    }
};
