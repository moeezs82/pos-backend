<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enhance payment_method_accounts into a full branch-owned payment-method
 * configuration table (dynamic methods such as KNET, Cheque, ...).
 *
 * Additive only. Stacks on 2026_07_16_000001 (which added `is_inherited`).
 * Safe to run on existing SQLite/MySQL production data: every column is
 * guarded with hasColumn and back-filled with sensible values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_method_accounts')) {
            return;
        }

        Schema::table('payment_method_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_method_accounts', 'display_name')) {
                $table->string('display_name')->nullable()->after('method');
            }
            if (!Schema::hasColumn('payment_method_accounts', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('account_id');
            }
            if (!Schema::hasColumn('payment_method_accounts', 'affects_cash_drawer')) {
                $table->boolean('affects_cash_drawer')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('payment_method_accounts', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('affects_cash_drawer');
            }
            if (!Schema::hasColumn('payment_method_accounts', 'icon_key')) {
                $table->string('icon_key')->nullable()->after('sort_order');
            }
            if (!Schema::hasColumn('payment_method_accounts', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('icon_key');
            }
            if (!Schema::hasColumn('payment_method_accounts', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });

        // Helpful index for the common "active methods for a branch, ordered" read.
        try {
            Schema::table('payment_method_accounts', function (Blueprint $table) {
                $table->index(['branch_id', 'is_active', 'sort_order'], 'pma_branch_active_sort_idx');
            });
        } catch (\Throwable $e) {
            // Index may already exist; ignore.
        }

        // ── Backfill presentation/behaviour for existing rows ──────────────────
        $defaults = [
            'cash'   => ['name' => 'Cash',          'drawer' => true,  'sort' => 1, 'icon' => 'cash'],
            'bank'   => ['name' => 'Bank Transfer', 'drawer' => false, 'sort' => 2, 'icon' => 'bank'],
            'card'   => ['name' => 'Card',          'drawer' => false, 'sort' => 3, 'icon' => 'card'],
            'knet'   => ['name' => 'KNET',          'drawer' => false, 'sort' => 4, 'icon' => 'knet'],
            'wallet' => ['name' => 'Wallet',        'drawer' => false, 'sort' => 5, 'icon' => 'wallet'],
            'cheque' => ['name' => 'Cheque',        'drawer' => false, 'sort' => 6, 'icon' => 'cheque'],
        ];

        DB::table('payment_method_accounts')->orderBy('id')->get()->each(function ($row) use ($defaults) {
            $code = strtolower((string) $row->method);
            $def  = $defaults[$code] ?? [
                'name'   => ucwords(str_replace(['_', '-'], ' ', $code)),
                'drawer' => $code === 'cash',
                'sort'   => 99,
                'icon'   => $code,
            ];

            DB::table('payment_method_accounts')->where('id', $row->id)->update([
                'display_name'        => $row->display_name ?? $def['name'],
                'is_active'           => $row->is_active ?? true,
                // Only cash affects the drawer by default; never inferred from the
                // literal word at runtime — always read this stored flag.
                'affects_cash_drawer' => $row->affects_cash_drawer ?? $def['drawer'],
                'sort_order'          => ($row->sort_order ?? 0) ?: $def['sort'],
                'icon_key'            => $row->icon_key ?? $def['icon'],
            ]);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_method_accounts')) {
            return;
        }

        try {
            Schema::table('payment_method_accounts', function (Blueprint $table) {
                $table->dropIndex('pma_branch_active_sort_idx');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('payment_method_accounts', function (Blueprint $table) {
            foreach ([
                'display_name', 'is_active', 'affects_cash_drawer',
                'sort_order', 'icon_key', 'created_by', 'updated_by',
            ] as $col) {
                if (Schema::hasColumn('payment_method_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
