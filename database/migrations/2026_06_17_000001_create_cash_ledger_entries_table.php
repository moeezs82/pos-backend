<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-Sales Cash Ledger.
 *
 * This migration does two things:
 *   1. Seeds three control/expense accounts into the EXISTING chart of accounts
 *      so the new categories post through the same double-entry journal that
 *      already powers the cash-flow report and party ledgers.
 *   2. Creates a thin companion table `cash_ledger_entries` that carries the
 *      POS-domain semantics (category, reference_name) and a 1:1 link to the
 *      journal_entries row that holds the actual financial truth.
 *
 * No existing table is altered. Sales / purchase / expense flows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1) Seed the GL accounts these categories post against.
        //    Idempotent: safe to re-run, will not duplicate.
        // ---------------------------------------------------------------
        $typeId = fn (string $code): ?int =>
            DB::table('account_types')->where('code', $code)->value('id');

        $assetTypeId   = $typeId('ASSET');
        $expenseTypeId = $typeId('EXPENSE');

        $now = now();

        $seed = [
            ['code' => '1300', 'name' => 'Loans Receivable (Personal/Party)', 'account_type_id' => $assetTypeId],
            ['code' => '1310', 'name' => 'Qameti / Committee Control',         'account_type_id' => $assetTypeId],
            ['code' => '5300', 'name' => 'Other / Sundry Expense',             'account_type_id' => $expenseTypeId],
        ];

        foreach ($seed as $row) {
            if ($row['account_type_id'] === null) {
                // account_types not seeded yet; skip silently so migration order is forgiving.
                continue;
            }

            DB::table('accounts')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name'            => $row['name'],
                    'account_type_id' => $row['account_type_id'],
                    'is_leaf'         => true,
                    'updated_at'      => $now,
                    'created_at'      => $now,
                ]
            );
        }

        // ---------------------------------------------------------------
        // 2) Companion domain table.
        // ---------------------------------------------------------------
        Schema::create('cash_ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->date('txn_date');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Domain classification (mirrors App\Enums\CashLedgerCategory)
            $table->enum('category', [
                'QAMETI_PAYMENT',
                'QAMETI_COLLECTION',
                'LOAN_GIVEN',
                'LOAN_RECOVERED',
                'OTHER_EXPENSE',
            ]);

            // Derived from category, stored for cheap filtering / indexing.
            $table->enum('direction', ['in', 'out']);

            $table->decimal('amount', 15, 2);

            // Which cash/bank account the money physically moved through.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('method')->nullable(); // cash | bank | card | wallet

            // Polymorphic party: App\Models\User | Customer | Vendor (FQCN, matching
            // the existing journal_postings.party convention). Nullable => unlinked.
            $table->nullableMorphs('party'); // party_type, party_id

            // Mandatory free-text identity when no party is linked (enforced in the
            // FormRequest, not at DB level, so historic/imported rows stay flexible).
            $table->string('reference_name')->nullable();
            $table->text('note')->nullable();

            // 1:1 link to the double-entry journal row that holds the money truth.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            // Reversal bookkeeping (void = post a reversing JE, keep the audit row).
            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->foreignId('reversal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['txn_date', 'branch_id']);
            $table->index(['category', 'direction']);
            $table->index(['status']);
            $table->index(['journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_ledger_entries');

        // Seeded accounts are intentionally NOT removed on rollback: by the time
        // this runs in production they may already carry postings, and dropping a
        // referenced account would violate restrictOnDelete on journal_postings.
    }
};
