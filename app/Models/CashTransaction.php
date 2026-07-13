<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'txn_date',
        'account_id',
        'branch_id',
        'register_shift_id',
        'type',
        'amount',
        'counterparty_type',
        'counterparty_id',
        'source_type',
        'source_id',
        'method',
        'reference',
        'voucher_no',
        'note',
        'status',
        'created_by',
        'approved_by'
    ];

    protected $casts = [
        'txn_date' => 'date',
        'amount'   => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CashTransaction $transaction) {
            if ($transaction->register_shift_id || !$transaction->branch_id) return;
            $userId = $transaction->created_by ?: auth()->id();
            if (!$userId) return;
            $transaction->register_shift_id = RegisterShift::query()
                ->where('cashier_id', $userId)->where('branch_id', $transaction->branch_id)
                ->where('status', 'open')->value('id');
        });
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function counterparty()
    {
        return $this->morphTo();
    }
    public function source()
    {
        return $this->morphTo();
    }

    public function isInflow(): bool
    {
        return in_array($this->type, ['receipt', 'transfer_in']);
    }
    public function isOutflow(): bool
    {
        return in_array($this->type, ['payment', 'expense', 'transfer_out']);
    }
}
