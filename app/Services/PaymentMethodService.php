<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentMethodAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Central, branch-aware resolver for payment methods.
 *
 *   branch + method code  ->  active configuration  ->  account + drawer behaviour
 *
 * This is the ONLY place allowed to translate a payment-method code into a
 * posting account. Controllers and other services must delegate here instead
 * of hard-coding Rule::in([...]) lists or account codes such as 1000/1010.
 */
class PaymentMethodService
{
    /**
     * Resolve a method to its full configuration for a branch.
     *
     * @param  bool  $allowInactive  Allow resolving an inactive method for
     *                               historical display. Never pass true when
     *                               creating a new financial transaction.
     *
     * @throws ValidationException  When the method/account is not usable.
     */
    public function resolve(?int $branchId, ?string $method, bool $allowInactive = false): PaymentMethodAccount
    {
        $code = PaymentMethodAccount::normalizeCode($method);

        if ($code === '') {
            throw ValidationException::withMessages([
                'method' => ['A payment method is required.'],
            ]);
        }

        $config = PaymentMethodAccount::query()
            ->with('account.type:id,code,name')
            ->forBranch($branchId)
            ->where('method', $code)
            ->first();

        if (!$config && $branchId) {
            throw ValidationException::withMessages([
                'method' => ["Payment method [{$code}] is not configured for this branch. Configure it in Master Admin › Payment Methods."],
            ]);
        }

        if (!$config) {
            throw ValidationException::withMessages([
                'method' => ["No global template found for payment method [{$code}]."],
            ]);
        }

        // Null-tolerant: if the enhancement migration has not run yet (column
        // absent) treat the method as active so posting never hard-breaks on a
        // deploy-before-migrate ordering.
        $isActive = $config->is_active ?? true;
        if (!$isActive && !$allowInactive) {
            throw ValidationException::withMessages([
                'method' => ["Payment method [{$config->presentation_name}] is inactive and cannot be used for new transactions."],
            ]);
        }

        $account = $config->account;

        if (!$account) {
            throw ValidationException::withMessages([
                'method' => ["Payment method [{$code}] has no posting account configured."],
            ]);
        }

        // Historical display may reference a since-deactivated account; only
        // enforce the asset/active guard for live (new-transaction) resolution.
        if (!$allowInactive) {
            if (!$account->is_active) {
                throw ValidationException::withMessages([
                    'method' => ["The account mapped to [{$config->presentation_name}] is inactive."],
                ]);
            }

            if (optional($account->type)->code !== 'ASSET') {
                throw ValidationException::withMessages([
                    'method' => ["Payment method [{$config->presentation_name}] must map to an asset account."],
                ]);
            }
        }

        return $config;
    }

    /** Resolve and return only the posting Account. */
    public function accountFor(?int $branchId, ?string $method, bool $allowInactive = false): Account
    {
        return $this->resolve($branchId, $method, $allowInactive)->account;
    }

    /** Whether the given method affects physical drawer cash for a branch. */
    public function affectsCashDrawer(?int $branchId, ?string $method): bool
    {
        try {
            return (bool) $this->resolve($branchId, $method, true)->affects_cash_drawer;
        } catch (ValidationException $e) {
            return false;
        }
    }

    /** Active methods for a branch, ordered for UI. */
    public function activeForBranch(?int $branchId): Collection
    {
        return PaymentMethodAccount::query()
            ->with('account:id,code,name,account_type_id,is_active')
            ->forBranch($branchId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('method')
            ->get();
    }

    /** Every configured method for a branch (Master Admin view). */
    public function allForBranch(?int $branchId): Collection
    {
        return PaymentMethodAccount::query()
            ->with('account:id,code,name,account_type_id,is_active')
            ->forBranch($branchId)
            ->orderBy('sort_order')
            ->orderBy('method')
            ->get();
    }

    /**
     * Distinct posting-account IDs used by a branch's methods. Reports use this
     * instead of the hard-coded 1000/1010 set. De-duplicated so several methods
     * mapping to one account are not double counted.
     *
     * @param  bool  $onlyDrawer   Restrict to drawer-affecting methods.
     * @param  bool  $activeOnly   Restrict to active methods.
     * @return int[]
     */
    public function accountIdsForBranch(?int $branchId, bool $onlyDrawer = false, bool $activeOnly = true): array
    {
        $q = PaymentMethodAccount::query()->forBranch($branchId);
        if ($activeOnly) $q->where('is_active', true);
        if ($onlyDrawer) $q->where('affects_cash_drawer', true);

        return $q->pluck('account_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * All monetary (cash/bank/clearing) posting-account IDs relevant to reports
     * for a branch — or across every branch when $branchId is null. Includes
     * INACTIVE methods so historical postings are still captured, plus the
     * legacy Cash(1000)/Bank(1010) accounts as a safety fallback. De-duplicated.
     *
     * @param  bool  $onlyDrawer  Restrict to drawer-affecting (physical cash) accounts.
     * @return int[]
     */
    public function monetaryAccountIds(?int $branchId = null, bool $onlyDrawer = false): array
    {
        $q = DB::table('payment_method_accounts');
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        if ($onlyDrawer && \Illuminate\Support\Facades\Schema::hasColumn('payment_method_accounts', 'affects_cash_drawer')) {
            $q->where('affects_cash_drawer', true);
        }
        $ids = $q->pluck('account_id')->map(fn ($i) => (int) $i)->all();

        // Legacy fallback so pre-configuration journals still appear.
        $legacyCodes = $onlyDrawer ? ['1000'] : ['1000', '1010'];
        $legacy = DB::table('accounts')->whereIn('code', $legacyCodes)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        return array_values(array_unique(array_merge($ids, $legacy)));
    }

    /**
     * Same set as monetaryAccountIds() but returned as account CODES, for
     * reports that filter by accounts.code.
     *
     * @return string[]
     */
    public function monetaryAccountCodes(?int $branchId = null, bool $onlyDrawer = false): array
    {
        $ids = $this->monetaryAccountIds($branchId, $onlyDrawer);
        if (empty($ids)) return [];

        return DB::table('accounts')->whereIn('id', $ids)
            ->pluck('code')->map(fn ($c) => (string) $c)->values()->all();
    }

    /**
     * Map of method-code => affects_cash_drawer for a branch. Useful where a
     * query has grouped by the stored method string and drawer behaviour must
     * be applied per row (with a cash fallback for legacy rows).
     *
     * @return array<string,bool>
     */
    public function drawerFlagsForBranch(?int $branchId): array
    {
        $flags = PaymentMethodAccount::query()
            ->forBranch($branchId)
            ->pluck('affects_cash_drawer', 'method')
            ->map(fn ($v) => (bool) $v)
            ->all();

        // The canonical 'cash' method IS physical drawer cash by definition, so
        // it always affects the drawer — even if a legacy/misconfigured branch
        // row has affects_cash_drawer = 0 (which previously caused Cash to be
        // counted in expected cash yet mislabelled "non-drawer").
        $flags['cash'] = true;

        return $flags;
    }
}
