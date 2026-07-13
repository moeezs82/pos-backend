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
            if (RegisterShift::where('cashier_id', $user->id)->where('status', 'open')->exists()) {
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
        $sales = DB::table('sales')->where('register_shift_id', $shift->id)->whereNull('deleted_at');
        $receipts = DB::table('receipts')->where('register_shift_id', $shift->id);
        $refunds = DB::table('sale_return_refunds')->where('register_shift_id', $shift->id);
        $ledger = DB::table('cash_ledger_entries')->where('register_shift_id', $shift->id)->where('status', 'posted')->whereNull('deleted_at');
        $moves = DB::table('shift_cash_movements')->where('register_shift_id', $shift->id);
        $cashTransactions = DB::table('cash_transactions')->where('register_shift_id', $shift->id)
            ->where('method', 'cash')->where('status', 'approved')->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('source_type')->orWhere('source_type', '!=', ShiftCashMovement::class);
            });

        $grossSales = (float) (clone $sales)->where('total', '>', 0)->sum('total');
        $inlineReturns = abs((float) (clone $sales)->where('total', '<', 0)->sum('total'));
        $cashSales = (float) (clone $receipts)->where('method', 'cash')->whereNotNull('sale_id')->sum('amount');
        $methods = (clone $receipts)->select('method', DB::raw('SUM(amount) amount'))->groupBy('method')->pluck('amount', 'method');
        $customerCashReceipts = (float) (clone $receipts)->where('method', 'cash')->whereNull('sale_id')->sum('amount');
        $cashRefunds = (float) (clone $refunds)->where('method', 'cash')->sum('amount');
        $ledgerIn = (float) (clone $ledger)->where('method', 'cash')->where('direction', 'in')->sum('amount');
        $ledgerOut = (float) (clone $ledger)->where('method', 'cash')->where('direction', 'out')->sum('amount');
        $manualIn = (float) (clone $moves)->where('direction', 'in')->sum('amount');
        $manualOut = (float) (clone $moves)->where('direction', 'out')->sum('amount');
        $cashTxnIn = (float) (clone $cashTransactions)->where('type', 'transfer_in')->sum('amount');
        $cashTxnOut = (float) (clone $cashTransactions)->whereIn('type', ['payment','expense','transfer_out'])
            ->where(function ($q) { $q->whereNull('source_type')->orWhere('source_type', '!=', \App\Models\SaleReturnRefund::class); })->sum('amount');
        $expected = round((float) $shift->opening_cash + $cashSales + $customerCashReceipts + $ledgerIn + $manualIn + $cashTxnIn - $cashRefunds - $ledgerOut - $manualOut - $cashTxnOut, 2);

        return [
            'gross_sales' => round($grossSales, 2), 'returns' => round($inlineReturns + (float) (clone $refunds)->sum('amount'), 2),
            'cash_sales' => round($cashSales, 2), 'card_sales' => round((float) ($methods['card'] ?? 0), 2),
            'bank_sales' => round((float) ($methods['bank'] ?? 0), 2), 'wallet_sales' => round((float) ($methods['wallet'] ?? 0), 2),
            'customer_cash_receipts' => round($customerCashReceipts, 2), 'cash_refunds' => round($cashRefunds, 2),
            'other_cash_in' => round($ledgerIn + $manualIn + $cashTxnIn, 2), 'other_cash_out' => round($ledgerOut + $manualOut + $cashTxnOut, 2),
            'expected_cash' => $expected, 'transaction_count' => (clone $sales)->count(),
            'pending_sync_count' => (int) $shift->pending_sync_count,
        ];
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
