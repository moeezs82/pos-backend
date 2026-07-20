<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('note');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_reason')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_cash_transaction_id')->nullable()->after('reversal_journal_entry_id')->constrained('cash_transactions')->nullOnDelete();
            $table->index(['branch_id', 'reversed_at'], 'idx_receipts_branch_reversed');
        });

        Schema::table('vendor_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('note');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversal_reason')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_cash_transaction_id')->nullable()->after('reversal_journal_entry_id')->constrained('cash_transactions')->nullOnDelete();
            $table->index(['branch_id', 'reversed_at'], 'idx_vendor_payments_branch_reversed');
        });

        $tables = (array) config('permission.table_names', []);
        $rolesTable = $tables['roles'] ?? 'roles';
        $permissionsTable = $tables['permissions'] ?? 'permissions';
        $pivotTable = $tables['role_has_permissions'] ?? 'role_has_permissions';

        if (Schema::hasTable($rolesTable) && Schema::hasTable($permissionsTable) && Schema::hasTable($pivotTable)) {
            $permissionId = DB::table($permissionsTable)->where('name', 'reverse-party-payments')->value('id');
            if (!$permissionId) {
                $permissionId = DB::table($permissionsTable)->insertGetId([
                    'name' => 'reverse-party-payments',
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $masterRoleIds = DB::table($rolesTable)
                ->whereIn(DB::raw('LOWER(name)'), ['master admin', 'master_admin', 'master-admin'])
                ->pluck('id');
            foreach ($masterRoleIds as $roleId) {
                DB::table($pivotTable)->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('vendor_payments', function (Blueprint $table) {
            $table->dropIndex('idx_vendor_payments_branch_reversed');
            $table->dropConstrainedForeignId('reversal_cash_transaction_id');
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex('idx_receipts_branch_reversed');
            $table->dropConstrainedForeignId('reversal_cash_transaction_id');
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
