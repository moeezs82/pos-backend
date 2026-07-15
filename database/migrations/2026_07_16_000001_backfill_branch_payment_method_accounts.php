<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_method_accounts')) {
            return;
        }

        if (!Schema::hasColumn('payment_method_accounts', 'is_inherited')) {
            Schema::table('payment_method_accounts', function (Blueprint $table) {
                $table->boolean('is_inherited')->default(false)->after('branch_id');
            });
        }

        $templates = DB::table('payment_method_accounts')
            ->whereNull('branch_id')
            ->get(['method', 'account_id']);

        if ($templates->isEmpty()) {
            return;
        }

        $now = now();
        DB::table('branches')->orderBy('id')->pluck('id')->each(function ($branchId) use ($templates, $now) {
            foreach ($templates as $template) {
                $exists = DB::table('payment_method_accounts')
                    ->where('method', $template->method)
                    ->where('branch_id', $branchId)
                    ->exists();

                if (!$exists) {
                    DB::table('payment_method_accounts')->insert([
                        'method'       => $template->method,
                        'account_id'   => $template->account_id,
                        'branch_id'    => $branchId,
                        'is_inherited' => true,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_method_accounts') || !Schema::hasColumn('payment_method_accounts', 'is_inherited')) {
            return;
        }

        DB::table('payment_method_accounts')
            ->whereNotNull('branch_id')
            ->where('is_inherited', true)
            ->delete();

        Schema::table('payment_method_accounts', function (Blueprint $table) {
            $table->dropColumn('is_inherited');
        });
    }
};
