<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-local invoice numbering — replaces the global invoice_counters table.
 *
 * PROBLEM: The previous invoice_counters table used only `ymd` (date) as its
 * primary key, so the daily sequence was shared across ALL branches. Two branches
 * would get INV-20260620-001 and INV-20260620-002, where they should each have
 * their own independent -001.
 *
 * FIX:
 * 1. Drop invoice_counters; create invoice_sequences with (branch_id, ymd) unique.
 * 2. Seed starting values from existing per-branch-per-date max sequence numbers
 *    so no existing invoice number is ever reused.
 * 3. Drop the global UNIQUE on sales.invoice_no; replace with UNIQUE(branch_id, invoice_no)
 *    so the same human number is allowed in different branches.
 * 4. Add offline_invoice_no column (customer-friendly offline receipt reference).
 *
 * MIGRATION IS REVERSIBLE — down() restores the old global scheme and removes
 * offline_invoice_no (data loss for that column on rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Replace global invoice_counters with branch-scoped invoice_sequences ──

        Schema::dropIfExists('invoice_counters');

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('ymd', 8);              // YYYYMMDD — invoice/occurrence date
            $table->unsignedBigInteger('next_seq')->default(1);
            $table->timestamps();

            // One counter per branch per day.  lockForUpdate() on this row is the
            // concurrency guarantee — only one transaction can hold the lock and
            // increment next_seq at a time.
            $table->unique(['branch_id', 'ymd'], 'invoice_sequences_branch_ymd_unique');
            $table->index('branch_id', 'invoice_sequences_branch_id_idx');

            // No FK to branches intentionally: keeps the migration reversible and avoids
            // a cascade issue if a branch is soft-deleted while its sequences remain.
        });

        // Seed from existing sales so the first new sale of any (branch, date)
        // continues the sequence rather than restarting at 1 and colliding with
        // an existing invoice number.
        $this->seedSequencesFromExistingSales();

        // ── 2. Fix the invoice_no uniqueness constraint on sales ──

        // Drop the old GLOBAL unique index (present since the original create_sales_table
        // migration). We must do this before adding a composite index that allows the
        // same invoice_no to exist across different branches.
        if ($this->indexExists('sales', 'sales_invoice_no_unique')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('sales_invoice_no_unique');
            });
        }

        // Add composite unique: the same invoice_no is fine in different branches,
        // but must remain unique within one branch (including soft-deleted rows,
        // which are intentionally kept for audit; invoice numbers are never reissued).
        if (!$this->indexExists('sales', 'sales_branch_invoice_no_unique')) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('CREATE UNIQUE INDEX sales_branch_invoice_no_unique ON sales (branch_id, invoice_no)');
            } else {
                Schema::table('sales', function (Blueprint $table) {
                    $table->unique(['branch_id', 'invoice_no'], 'sales_branch_invoice_no_unique');
                });
            }
        }

        // ── 3. Add offline_invoice_no to sales ──

        if (!Schema::hasColumn('sales', 'offline_invoice_no')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('offline_invoice_no', 80)->nullable()->after('invoice_no');
                // Global unique: the format OFF-B{id}-{code}-{date}-{seq} encodes
                // branch identity, so collisions across branches cannot happen unless
                // two physical devices share the same register code within a branch
                // (which is a configuration error the operator must avoid).
                $table->unique('offline_invoice_no', 'sales_offline_invoice_no_unique');
                $table->index('offline_invoice_no', 'sales_offline_invoice_no_idx');
            });
        }
    }

    public function down(): void
    {
        // Remove offline_invoice_no
        if (Schema::hasColumn('sales', 'offline_invoice_no')) {
            Schema::table('sales', function (Blueprint $table) {
                if ($this->indexExists('sales', 'sales_offline_invoice_no_unique')) {
                    $table->dropUnique('sales_offline_invoice_no_unique');
                }
                if ($this->indexExists('sales', 'sales_offline_invoice_no_idx')) {
                    $table->dropIndex('sales_offline_invoice_no_idx');
                }
                $table->dropColumn('offline_invoice_no');
            });
        }

        // Restore global unique on invoice_no
        if ($this->indexExists('sales', 'sales_branch_invoice_no_unique')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('sales_branch_invoice_no_unique');
            });
        }
        if (!$this->indexExists('sales', 'sales_invoice_no_unique')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique('invoice_no', 'sales_invoice_no_unique');
            });
        }

        // Restore global counter table
        Schema::dropIfExists('invoice_sequences');

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('ymd', 8)->primary();
            $table->unsignedBigInteger('next_seq')->default(1);
            $table->timestamps();
        });
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Populate invoice_sequences from the max sequence already used per
     * (branch_id, ymd) in the sales table, including soft-deleted rows.
     *
     * Pattern: "INV-YYYYMMDD-NNN" → ymd = substr(invoice_no, 4, 8), seq = tail digits.
     */
    private function seedSequencesFromExistingSales(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: CAST(SUBSTR(...) AS INTEGER) for the numeric tail
            $rows = DB::select(<<<SQL
                SELECT
                    branch_id,
                    SUBSTR(invoice_no, 5, 8)                          AS ymd,
                    MAX(CAST(SUBSTR(invoice_no, 14) AS INTEGER)) + 1  AS next_seq
                FROM sales
                WHERE invoice_no LIKE 'INV-________-%'
                  AND branch_id IS NOT NULL
                GROUP BY branch_id, SUBSTR(invoice_no, 5, 8)
            SQL);
        } else {
            // MySQL / MariaDB: CAST(SUBSTRING(...) AS UNSIGNED)
            $rows = DB::select(<<<SQL
                SELECT
                    branch_id,
                    SUBSTRING(invoice_no, 5, 8)                              AS ymd,
                    MAX(CAST(SUBSTRING(invoice_no, 14) AS UNSIGNED)) + 1     AS next_seq
                FROM sales
                WHERE invoice_no LIKE 'INV-________-%'
                  AND branch_id IS NOT NULL
                GROUP BY branch_id, SUBSTRING(invoice_no, 5, 8)
            SQL);
        }

        $now = now();
        foreach ($rows as $row) {
            DB::table('invoice_sequences')->insert([
                'branch_id'  => $row->branch_id,
                'ymd'        => $row->ymd,
                'next_seq'   => max(1, (int) $row->next_seq),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Check whether a named index exists on a table.
     * Uses information_schema for MySQL/MariaDB; sqlite_master for SQLite.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?",
                [$table, $indexName]
            );
            return !empty($result);
        }

        // MySQL / MariaDB
        $result = DB::select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1",
            [$table, $indexName]
        );
        return !empty($result);
    }
};
