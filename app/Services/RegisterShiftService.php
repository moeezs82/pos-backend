<?php

namespace App\Services;

use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\ShiftCashMovement;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterShiftService
{
    public function open(Register $register, User $user, array $data): RegisterShift
    {
        if ((int) $register->branch_id !== (int) $user->branch_id || !$register->is_active) {
            throw ValidationException::withMessages(['register_id' => ['Register is not available in your active branch.']]);
        }

        return DB::transaction(function () use ($register, $user, $data) {
            $existing = RegisterShift::where('client_ref', $data['client_ref'])->first();
            if ($existing) return $existing;

            Register::whereKey($register->id)->lockForUpdate()->first();
            User::whereKey($user->id)->lockForUpdate()->first();
            if (RegisterShift::where('register_id', $register->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['register_id' => ['This register already has an open shift.']]);
            }
            if (RegisterShift::where('cashier_id', $user->id)->where('branch_id', $user->branch_id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['cashier' => ['You already have an open register shift.']]);
            }

            return RegisterShift::create([
                'client_ref' => $data['client_ref'], 'register_id' => $register->id,
                'branch_id' => $register->branch_id, 'cashier_id' => $user->id,
                'opened_by' => $user->id, 'status' => 'open',
                'opened_at' => $data['opened_at'] ?? now(),
                'opening_cash' => round((float) $data['opening_cash'], 2),
                'opening_note' => $data['opening_note'] ?? null,
                'device_identifier' => $data['device_identifier'] ?? $register->device_identifier,
            ]);
        }, 3);
    }

    public function summary(RegisterShift $shift): array
    {
        $branchId = $shift->branch_id ? (int) $shift->branch_id : null;

        // Which method codes physically affect the drawer for this branch. Read
        // from configuration (affects_cash_drawer) — never inferred from the
        // literal word "cash". Defaults to ['cash'] if config is missing.
        $pmService   = app(\App\Services\PaymentMethodService::class);
        $drawerFlags = $pmService->drawerFlagsForBranch($branchId);
        $drawerCodes = array_keys(array_filter($drawerFlags));
        if (empty($drawerCodes)) {
            $drawerCodes = ['cash'];
        }

        $sales = DB::table('sales')->where('register_shift_id', $shift->id)->whereNull('deleted_at');
        $receipts = DB::table('receipts')->where('register_shift_id', $shift->id);
        $refunds = DB::table('sale_return_refunds')->where('register_shift_id', $shift->id);
        $inlineRefunds = DB::table('sale_refunds')->where('register_shift_id', $shift->id);
        $ledger = DB::table('cash_ledger_entries')->where('register_shift_id', $shift->id)->where('status', 'posted')->whereNull('deleted_at');
        $moves = DB::table('shift_cash_movements')->where('register_shift_id', $shift->id);
        // Only drawer methods change expected physical cash.
        $cashTransactions = DB::table('cash_transactions')->where('register_shift_id', $shift->id)
            ->whereIn('method', $drawerCodes)->where('status', 'approved')->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhere('source_type', '!=', ShiftCashMovement::class);
            });

        $grossSales = (float) (clone $sales)->where('total', '>', 0)->sum('total');
        $inlineReturns = abs((float) (clone $sales)->where('total', '<', 0)->sum('total'));
        $cashSales = (float) (clone $receipts)->whereIn('method', $drawerCodes)->whereNotNull('sale_id')->sum('amount');
        $methods = (clone $receipts)->select('method', DB::raw('SUM(amount) amount'))->groupBy('method')->pluck('amount', 'method');
        $customerCashReceipts = (float) (clone $receipts)->whereIn('method', $drawerCodes)->whereNull('sale_id')->sum('amount');
        $cashRefunds = (float) (clone $refunds)->whereIn('method', $drawerCodes)->sum('amount')
            + (float) (clone $inlineRefunds)->whereIn('method', $drawerCodes)->sum('amount');
        $ledgerIn = (float) (clone $ledger)->whereIn('method', $drawerCodes)->where('direction', 'in')->sum('amount');
        $ledgerOut = (float) (clone $ledger)->whereIn('method', $drawerCodes)->where('direction', 'out')->sum('amount');
        $manualIn = (float) (clone $moves)->where('direction', 'in')->sum('amount');
        $manualOut = (float) (clone $moves)->where('direction', 'out')->sum('amount');
        $cashTxnIn = (float) (clone $cashTransactions)->where('type', 'transfer_in')->sum('amount');
        $cashTxnOut = (float) (clone $cashTransactions)->whereIn('type', ['payment','expense','transfer_out'])
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhereNotIn('source_type', [
                    \App\Models\SaleReturnRefund::class,
                    \App\Models\SaleRefund::class,
                ]);
            })->sum('amount');
        $expected = round((float) $shift->opening_cash + $cashSales + $customerCashReceipts + $ledgerIn + $manualIn + $cashTxnIn - $cashRefunds - $ledgerOut - $manualOut - $cashTxnOut, 2);

        // ── Dynamic per-method breakdown (in/out) ──────────────────────────
        // Non-drawer methods (KNET, card, bank, …) appear here for visibility
        // but do NOT change expected physical cash.
        $agg = [];
        $add = function (?string $method, float $in, float $out) use (&$agg) {
            $m = $method ?: 'cash';
            $agg[$m] ??= ['in' => 0.0, 'out' => 0.0];
            $agg[$m]['in'] += $in;
            $agg[$m]['out'] += $out;
        };
        foreach ((clone $receipts)->select('method', DB::raw('SUM(amount) a'))->groupBy('method')->get() as $r) $add($r->method, (float) $r->a, 0);
        foreach ((clone $refunds)->select('method', DB::raw('SUM(amount) a'))->groupBy('method')->get() as $r) $add($r->method, 0, (float) $r->a);
        foreach ((clone $inlineRefunds)->select('method', DB::raw('SUM(amount) a'))->groupBy('method')->get() as $r) $add($r->method, 0, (float) $r->a);
        foreach ((clone $ledger)->select('method', 'direction', DB::raw('SUM(amount) a'))->groupBy('method', 'direction')->get() as $r) {
            $r->direction === 'in' ? $add($r->method, (float) $r->a, 0) : $add($r->method, 0, (float) $r->a);
        }
        $add('cash', $manualIn, $manualOut);

        // Operational cash transactions across ALL methods (vendor payments,
        // claim receipts, expenses). Exclude shift movements (counted above) and
        // sale-refund mirrors (already counted via the refunds tables).
        $ctBreakdown = DB::table('cash_transactions')->where('register_shift_id', $shift->id)
            ->where('status', 'approved')->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhere('source_type', '!=', ShiftCashMovement::class);
            })
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhereNotIn('source_type', [
                    \App\Models\SaleReturnRefund::class,
                    \App\Models\SaleRefund::class,
                ]);
            });
        foreach ((clone $ctBreakdown)->select('method', 'type', DB::raw('SUM(amount) a'))->groupBy('method', 'type')->get() as $r) {
            if (in_array($r->type, ['receipt', 'transfer_in'], true)) {
                $add($r->method, (float) $r->a, 0);
            } elseif (in_array($r->type, ['payment', 'expense', 'transfer_out'], true)) {
                $add($r->method, 0, (float) $r->a);
            }
        }

        $names = DB::table('payment_method_accounts')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->pluck('display_name', 'method');

        $methodTotals = [];
        foreach ($agg as $code => $io) {
            $methodTotals[] = [
                'method'              => $code,
                'name'                => $names[$code] ?? ucwords(str_replace(['_', '-'], ' ', $code)),
                'in'                  => round($io['in'], 2),
                'out'                 => round($io['out'], 2),
                // Derive the badge from the SAME resolved drawer set used by the
                // expected-cash math above, so a method counted as physical cash
                // can never be labelled "non-drawer" (and vice-versa).
                'affects_cash_drawer' => in_array($code, $drawerCodes, true),
            ];
        }

        return [
            'gross_sales' => round($grossSales, 2), 'returns' => round($inlineReturns + (float) (clone $refunds)->sum('amount'), 2),
            'cash_sales' => round($cashSales, 2), 'card_sales' => round((float) ($methods['card'] ?? 0), 2),
            'bank_sales' => round((float) ($methods['bank'] ?? 0), 2), 'wallet_sales' => round((float) ($methods['wallet'] ?? 0), 2),
            'customer_cash_receipts' => round($customerCashReceipts, 2), 'cash_refunds' => round($cashRefunds, 2),
            'other_cash_in' => round($ledgerIn + $manualIn + $cashTxnIn, 2), 'other_cash_out' => round($ledgerOut + $manualOut + $cashTxnOut, 2),
            'expected_cash' => $expected, 'transaction_count' => (clone $sales)->count(),
            'pending_sync_count' => (int) $shift->pending_sync_count,
            'method_totals' => $methodTotals,
        ];
    }

    /**
     * Unified, READ-ONLY drawer activity for a shift: every posted, shift-linked
     * transaction that changes physical drawer cash — cash sales, customer
     * receipts, refunds, shift-linked Cash Ledger entries, manual movements and
     * non-mirror cash transactions — using the SAME inclusion rules as
     * summary(). This never writes a row; it only aggregates canonical records.
     *
     * Returns a directional, paginated feed (latest first) plus a reconciliation
     * block so the shift's expected cash can be reconstructed from the list.
     */
    public function activity(RegisterShift $shift, array $opts = []): array
    {
        $branchId    = $shift->branch_id ? (int) $shift->branch_id : null;
        $pmService   = app(\App\Services\PaymentMethodService::class);
        $drawerFlags = $pmService->drawerFlagsForBranch($branchId);
        $drawerCodes = array_keys(array_filter($drawerFlags));
        if (empty($drawerCodes)) {
            $drawerCodes = ['cash'];
        }

        $rows = $this->drawerActivityRows($shift, $drawerCodes);

        // Resolve creator names in one query.
        $userIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['user_id'] ?? null, $rows))));
        $userNames = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')->all()
            : [];
        foreach ($rows as &$r) {
            $r['user_name'] = $r['user_id'] ? ($userNames[$r['user_id']] ?? null) : null;
        }
        unset($r);

        $totalIn  = round(array_sum(array_map(fn ($r) => $r['direction'] === 'in' ? $r['amount'] : 0.0, $rows)), 2);
        $totalOut = round(array_sum(array_map(fn ($r) => $r['direction'] === 'out' ? $r['amount'] : 0.0, $rows)), 2);

        $opening  = round((float) $shift->opening_cash, 2);
        $expected = $this->summary($shift)['expected_cash'];
        $reconExpected = round($opening + $totalIn - $totalOut, 2);

        // Latest-first, deterministic tie-break by source key.
        usort($rows, function ($a, $b) {
            $c = strcmp((string) $b['occurred_at'], (string) $a['occurred_at']);
            return $c !== 0 ? $c : strcmp((string) $b['key'], (string) $a['key']);
        });

        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 50)));
        $total   = count($rows);
        $lastPage = (int) max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($lastPage, (int) ($opts['page'] ?? 1)));
        $items   = array_slice($rows, ($page - 1) * $perPage, $perPage);
        // Strip the internal sort helper.
        $items = array_map(function ($r) {
            unset($r['user_id']);
            return $r;
        }, $items);

        return [
            'reconciliation' => [
                'opening_cash'      => $opening,
                'drawer_in'         => $totalIn,
                'drawer_out'        => $totalOut,
                'expected_cash'     => $expected,
                'activity_total_in' => $totalIn,
                'activity_total_out'=> $totalOut,
                'reconciled_expected' => $reconExpected,
                'is_reconciled'     => abs($reconExpected - (float) $expected) < 0.005,
            ],
            'items'        => array_values($items),
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => $lastPage,
        ];
    }

    /**
     * Canonical drawer-affecting rows for a shift. Each economic event is read
     * from its single canonical table exactly once; cash_transactions excludes
     * shift-movement mirrors and sale-refund mirrors that are already counted
     * via their own tables (matching summary()'s dedup rules).
     *
     * @return array<int, array<string, mixed>>
     */
    private function drawerActivityRows(RegisterShift $shift, array $drawerCodes): array
    {
        $rows = [];

        // 1) Receipts: cash sales (sale_id set) and customer receipts (no sale).
        foreach (DB::table('receipts')->where('register_shift_id', $shift->id)
            ->whereIn('method', $drawerCodes)->get() as $r) {
            $isSale = $r->sale_id !== null;
            $rows[] = [
                'key'         => 'receipt#' . $r->id,
                'source_type' => 'receipt',
                'source_id'   => (int) $r->id,
                'kind'        => $isSale ? 'cash_sale' : 'customer_receipt',
                'label'       => $isSale ? 'Cash Sale' : 'Customer Receipt',
                'occurred_at' => (string) ($r->received_at ?? $r->created_at ?? ''),
                'direction'   => 'in',
                'amount'      => round((float) $r->amount, 2),
                'method'      => $r->method,
                'reference'   => $r->reference,
                'note'        => $r->note,
                'status'      => 'posted',
                'user_id'     => $r->created_by,
                'is_manual'   => false,
            ];
        }

        // 2) Refunds (drawer): sale-return refunds + inline sale refunds.
        foreach ([
            ['sale_return_refunds', 'Sale Refund'],
            ['sale_refunds', 'Sale Refund'],
        ] as [$table, $label]) {
            foreach (DB::table($table)->where('register_shift_id', $shift->id)
                ->whereIn('method', $drawerCodes)->get() as $r) {
                $a = (array) $r; // optional columns vary by table — access safely
                $rows[] = [
                    'key'         => $table . '#' . $a['id'],
                    'source_type' => $table,
                    'source_id'   => (int) $a['id'],
                    'kind'        => 'sale_refund',
                    'label'       => $label,
                    'occurred_at' => (string) ($a['refunded_at'] ?? $a['created_at'] ?? ''),
                    'direction'   => 'out',
                    'amount'      => round((float) $a['amount'], 2),
                    'method'      => $a['method'] ?? 'cash',
                    'reference'   => $a['reference'] ?? ($a['note'] ?? null),
                    'note'        => $a['note'] ?? null,
                    'status'      => 'posted',
                    'user_id'     => $a['created_by'] ?? null,
                    'is_manual'   => false,
                ];
            }
        }

        // 3) Cash Ledger entries (posted, drawer): expenses, loans, qameti.
        $ledgerLabels = [
            'OTHER_EXPENSE'     => 'Other Expense',
            'LOAN_GIVEN'        => 'Loan Given',
            'LOAN_RECOVERED'    => 'Loan Recovered',
            'QAMETI_PAYMENT'    => 'Qameti Payment',
            'QAMETI_COLLECTION' => 'Qameti Collection',
        ];
        foreach (DB::table('cash_ledger_entries')->where('register_shift_id', $shift->id)
            ->where('status', 'posted')->whereNull('deleted_at')
            ->whereIn('method', $drawerCodes)->get() as $r) {
            $rows[] = [
                'key'         => 'cash_ledger_entry#' . $r->id,
                'source_type' => 'cash_ledger_entry',
                'source_id'   => (int) $r->id,
                'kind'        => strtolower((string) $r->category),
                'label'       => $ledgerLabels[$r->category] ?? ucwords(strtolower(str_replace('_', ' ', (string) $r->category))),
                'occurred_at' => (string) ($r->txn_date ?? $r->created_at ?? ''),
                'direction'   => $r->direction === 'in' ? 'in' : 'out',
                'amount'      => round((float) $r->amount, 2),
                'method'      => $r->method,
                'reference'   => $r->reference_name,
                'note'        => $r->note,
                'status'      => 'posted',
                'user_id'     => $r->created_by,
                'is_manual'   => false,
            ];
        }

        // 4) Manual shift cash movements (float in, banking out, corrections).
        foreach (DB::table('shift_cash_movements')->where('register_shift_id', $shift->id)->get() as $r) {
            $rows[] = [
                'key'         => 'shift_cash_movement#' . $r->id,
                'source_type' => 'shift_cash_movement',
                'source_id'   => (int) $r->id,
                'kind'        => $r->direction === 'in' ? 'manual_cash_in' : 'manual_cash_out',
                'label'       => $r->direction === 'in' ? 'Manual Cash In' : 'Manual Cash Out',
                'occurred_at' => (string) ($r->occurred_at ?? $r->created_at ?? ''),
                'direction'   => $r->direction === 'in' ? 'in' : 'out',
                'amount'      => round((float) $r->amount, 2),
                'method'      => 'cash',
                'reference'   => $r->reason ?? null,
                'note'        => $r->note,
                'status'      => 'posted',
                'user_id'     => $r->created_by,
                'is_manual'   => true,
            ];
        }

        // 5) Non-mirror cash transactions (vendor payments, claim receipts, …).
        $ctLabels = [
            'receipt' => 'Cash Received', 'transfer_in' => 'Transfer In',
            'payment' => 'Vendor Payment', 'expense' => 'Cash Expense', 'transfer_out' => 'Transfer Out',
        ];
        $cts = DB::table('cash_transactions')->where('register_shift_id', $shift->id)
            ->whereIn('method', $drawerCodes)->where('status', 'approved')->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhere('source_type', '!=', ShiftCashMovement::class);
            })
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhereNotIn('source_type', [
                    \App\Models\SaleReturnRefund::class,
                    \App\Models\SaleRefund::class,
                ]);
            })->get();
        foreach ($cts as $r) {
            $in = in_array($r->type, ['receipt', 'transfer_in'], true);
            $out = in_array($r->type, ['payment', 'expense', 'transfer_out'], true);
            if (!$in && !$out) continue;
            $rows[] = [
                'key'         => 'cash_transaction#' . $r->id,
                'source_type' => 'cash_transaction',
                'source_id'   => (int) $r->id,
                'kind'        => (string) $r->type,
                'label'       => $ctLabels[$r->type] ?? ucwords(str_replace('_', ' ', (string) $r->type)),
                'occurred_at' => (string) ($r->txn_date ?? $r->created_at ?? ''),
                'direction'   => $in ? 'in' : 'out',
                'amount'      => round((float) $r->amount, 2),
                'method'      => $r->method,
                'reference'   => $r->reference ?? $r->voucher_no ?? null,
                'note'        => $r->note,
                'status'      => 'posted',
                'user_id'     => $r->created_by,
                'is_manual'   => false,
            ];
        }

        return $rows;
    }

    public function movement(RegisterShift $shift, User $user, array $data): ShiftCashMovement
    {
        if (!$shift->isOpen()) throw ValidationException::withMessages(['shift' => ['Closed shifts cannot be changed.']]);
        $approvalLimit = (float) config('pos.shift_cash_out_approval_limit', 500);
        $requiresApproval = $data['direction'] === 'out' && (float) $data['amount'] > $approvalLimit;
        if ($requiresApproval && !$user->can('approve-shift-cash-movement')) {
            throw ValidationException::withMessages([
                'amount' => ["Cash Out above {$approvalLimit} requires a manager or owner."],
            ]);
        }
        return DB::transaction(function () use ($shift, $user, $data, $requiresApproval) {
            $existing = ShiftCashMovement::where('client_ref', $data['client_ref'])->first();
            if ($existing) return $existing;

            $movement = ShiftCashMovement::create([
                'client_ref' => $data['client_ref'],
                'register_shift_id' => $shift->id, 'branch_id' => $shift->branch_id,
                'direction' => $data['direction'], 'amount' => round((float) $data['amount'], 2),
                'reason' => $data['reason'], 'note' => $data['note'] ?? null,
                'approval_status' => $requiresApproval ? 'approved' : 'not_required',
                'approved_by' => $requiresApproval ? $user->id : null,
                'created_by' => $user->id, 'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $cashAccount = app(CashSyncService::class)->mapMethodToAccount('cash', $shift->branch_id);
            CashTransaction::create([
                'txn_date' => $movement->occurred_at->toDateString(),
                'account_id' => $cashAccount->id,
                'branch_id' => $shift->branch_id,
                'register_shift_id' => $shift->id,
                'type' => $movement->direction === 'in' ? 'transfer_in' : 'transfer_out',
                'amount' => $movement->amount,
                'method' => 'cash',
                'reference' => 'Register Shift #' . $shift->id,
                'note' => $movement->reason . ($movement->note ? ': ' . $movement->note : ''),
                'status' => 'approved',
                'created_by' => $user->id,
                'source_type' => ShiftCashMovement::class,
                'source_id' => $movement->id,
            ]);

            return $movement->fresh('creator:id,name');
        });
    }

    public function close(RegisterShift $shift, User $user, array $data, bool $force = false): RegisterShift
    {
        return DB::transaction(function () use ($shift, $user, $data, $force) {
            $shift = RegisterShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (!$shift->isOpen()) return $shift;
            if (!$force && (int) $shift->cashier_id !== (int) $user->id) abort(403, 'You can only close your own shift.');
            $pending = (int) ($data['pending_sync_count'] ?? 0);
            $acceptPending = (bool) ($data['accept_pending_sync'] ?? false);
            if ($pending > 0 && (!$acceptPending || !$user->can('manage-register-shifts'))) {
                throw ValidationException::withMessages(['pending_sync_count' => ['Pending offline sales must be synchronized, or explicitly accepted by an authorized manager.']]);
            }
            $summary = $this->summary($shift);
            $counted = round((float) $data['counted_cash'], 2);
            $variance = round($counted - $summary['expected_cash'], 2);
            $limit = (float) config('pos.shift_variance_approval_limit', 5);
            if (abs($variance) > $limit && !$user->can('approve-shift-variance')) {
                throw ValidationException::withMessages(['counted_cash' => ["Variance exceeds the manager approval limit of {$limit}."]]);
            }
            $shift->update([
                'status' => $force ? 'force_closed' : 'closed', 'closed_at' => now(), 'closed_by' => $user->id,
                'approved_by' => abs($variance) > $limit ? $user->id : null,
                'expected_cash' => $summary['expected_cash'], 'counted_cash' => $counted, 'variance' => $variance,
                'closing_note' => $data['closing_note'] ?? null, 'approval_note' => $data['approval_note'] ?? null,
                'pending_sync_count' => $pending, 'pending_sync_accepted' => $acceptPending,
            ]);
            return $shift->fresh();
        }, 3);
    }
}
