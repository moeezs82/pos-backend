<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates branch_subscriptions and subscription_audits tables.
 *
 * SAFE DEFAULT STRATEGY
 * ---------------------
 * Existing branches receive NO subscription row.  The SubscriptionStatusService
 * treats the absence of a row as "active with no expiry" so that every branch
 * currently in production continues working after this migration runs without
 * any manual intervention.  The SaaS Owner then assigns subscription dates
 * through the owner management API at their own pace.
 *
 * STATUS SEMANTICS
 * ----------------
 *   trial        – within a free-trial window; expires_at drives the cutoff.
 *   active       – paid and active; expires_at drives the cutoff (nullable =
 *                  never expires).
 *   grace_period – expired but still accessible; grace_until drives the cutoff.
 *   expired      – access blocked; admin-set or derived when dates pass.
 *   suspended    – access blocked regardless of all dates; requires a reason.
 *
 * is_locked calculation (done in SubscriptionStatusService, never client-side):
 *   true  if status == 'suspended'
 *   true  if status == 'expired'
 *   true  if status in ('active','trial') AND expires_at IS NOT NULL AND expires_at < now()
 *   true  if status == 'grace_period' AND grace_until IS NOT NULL AND grace_until < now()
 *   false otherwise (including no subscription row)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Branch subscriptions ───────────────────────────────────────────────
        Schema::create('branch_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')
                ->unique()
                ->constrained('branches')
                ->cascadeOnDelete();

            // Admin-curated status.  The service derives the *effective* locked
            // state from this plus the date columns.
            $table->string('status', 50)->default('active');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('grace_until')->nullable();

            // Populated when status is set to 'suspended'.
            $table->string('suspended_reason', 500)->nullable();

            // Extra notes the SaaS Owner can attach (e.g. payment reference).
            $table->text('notes')->nullable();

            // Who last changed the record (foreign key not enforced to allow
            // soft-deleted users to remain as actors in the audit trail).
            $table->unsignedBigInteger('managed_by')->nullable();

            $table->timestamps();
        });

        // ── Subscription audit log ────────────────────────────────────────────
        Schema::create('subscription_audits', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('changed_by')->nullable(); // user_id

            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->timestamp('old_expires_at')->nullable();
            $table->timestamp('new_expires_at')->nullable();
            $table->timestamp('old_grace_until')->nullable();
            $table->timestamp('new_grace_until')->nullable();
            $table->string('action', 100)->nullable(); // e.g. 'renew', 'suspend', 'reactivate'
            $table->text('reason')->nullable();

            // Flexible field for any additional context (old/new full record, etc.)
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_audits');
        Schema::dropIfExists('branch_subscriptions');
    }
};
