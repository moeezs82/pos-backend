<?php

namespace App\Models;

use App\Enums\CashLedgerCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashLedgerEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'txn_date',
        'branch_id',
        'category',
        'direction',
        'amount',
        'account_id',
        'method',
        'party_type',
        'party_id',
        'reference_name',
        'note',
        'journal_entry_id',
        'status',
        'reversal_entry_id',
        'created_by',
        'voided_by',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'amount'   => 'decimal:2',
        'category' => CashLedgerCategory::class,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    /** App\Models\User | Customer | Vendor, or null when unlinked. */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function isInflow(): bool
    {
        return $this->direction === 'in';
    }

    public function isVoided(): bool
    {
        return $this->status === 'void';
    }

    // ---- Query scopes used by the listing endpoint -------------------

    public function scopeForBranch(Builder $q, ?int $branchId): Builder
    {
        return $branchId ? $q->where('branch_id', $branchId) : $q;
    }

    public function scopeCategory(Builder $q, CashLedgerCategory|string|null $category): Builder
    {
        if (!$category) {
            return $q;
        }
        $value = $category instanceof CashLedgerCategory ? $category->value : $category;
        return $q->where('category', $value);
    }

    public function scopeParty(Builder $q, ?string $partyType, ?int $partyId): Builder
    {
        if ($partyType) {
            $q->where('party_type', $partyType);
        }
        if ($partyId) {
            $q->where('party_id', $partyId);
        }
        return $q;
    }

    public function scopeDateRange(Builder $q, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $q->whereDate('txn_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('txn_date', '<=', $to);
        }
        return $q;
    }
}
