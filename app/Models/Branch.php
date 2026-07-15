<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class Branch extends Model
{
    use SoftDeletes;
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (Branch $branch) {
            if (!Schema::hasTable('payment_method_accounts')
                || !Schema::hasColumn('payment_method_accounts', 'is_inherited')) {
                return;
            }

            PaymentMethodAccount::query()
                ->whereNull('branch_id')
                ->get(['method', 'account_id'])
                ->each(function (PaymentMethodAccount $template) use ($branch) {
                    PaymentMethodAccount::firstOrCreate(
                        ['method' => $template->method, 'branch_id' => $branch->id],
                        ['account_id' => $template->account_id, 'is_inherited' => true]
                    );
                });
        });
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }
}
