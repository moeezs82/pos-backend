<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Http\Response\ApiResponse;
use App\Models\Account;
use App\Models\PaymentMethodAccount;
use App\Services\BranchContextService;
use App\Services\PaymentMethodService;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function __construct(
        private PaymentMethodService $methods,
        private BranchContextService $branches,
    ) {}

    /**
     * Operational read: active methods for the caller's effective branch.
     * Available to any authenticated branch user (used by sale, purchase,
     * receipt, refund and expense screens).
     */
    public function index(Request $request)
    {
        $branchId = $this->branches->effectiveBranchId($request);

        return ApiResponse::success([
            'branch_id'       => $branchId,
            'payment_methods' => $this->methods->activeForBranch($branchId)->map(
                fn (PaymentMethodAccount $m) => $this->present($m)
            )->values(),
        ]);
    }

    /**
     * Master-admin read: every configured method for a chosen branch.
     */
    public function adminIndex(Request $request)
    {
        if (!$this->branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage payment methods.', 403);
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        return ApiResponse::success([
            'branch_id'       => (int) $data['branch_id'],
            'payment_methods' => $this->methods->allForBranch((int) $data['branch_id'])->map(
                fn (PaymentMethodAccount $m) => $this->present($m, true)
            )->values(),
        ]);
    }

    public function store(StorePaymentMethodRequest $request)
    {
        $data = $request->validated();

        if ($error = $this->assertAssetAccount((int) $data['account_id'])) {
            return $error;
        }

        $method = PaymentMethodAccount::create([
            'branch_id'           => (int) $data['branch_id'],
            'method'              => $data['method'],
            'display_name'        => $data['display_name'],
            'account_id'          => (int) $data['account_id'],
            'is_active'           => $data['is_active'] ?? true,
            'affects_cash_drawer' => $data['affects_cash_drawer'] ?? false,
            'sort_order'          => $data['sort_order'] ?? 0,
            'icon_key'            => $data['icon_key'] ?? null,
            'is_inherited'        => false,
            'created_by'          => $request->user()?->id,
            'updated_by'          => $request->user()?->id,
        ]);

        return ApiResponse::success(
            ['payment_method' => $this->present($method->fresh('account.type'), true)],
            'Payment method created.'
        );
    }

    public function update(UpdatePaymentMethodRequest $request, int $id)
    {
        $method = PaymentMethodAccount::findOrFail($id);
        $data = $request->validated();

        if (array_key_exists('account_id', $data)) {
            if ($error = $this->assertAssetAccount((int) $data['account_id'])) {
                return $error;
            }
        }

        // `method` (machine code) is never changed here — remapping the account
        // affects only FUTURE postings; historical journals keep their account.
        $method->fill($data);
        $method->is_inherited = false;
        $method->updated_by = $request->user()?->id;
        $method->save();

        return ApiResponse::success(
            ['payment_method' => $this->present($method->fresh('account.type'), true)],
            'Payment method updated.'
        );
    }

    public function activate(Request $request, int $id)
    {
        return $this->toggle($request, $id, true);
    }

    public function deactivate(Request $request, int $id)
    {
        return $this->toggle($request, $id, false);
    }

    private function toggle(Request $request, int $id, bool $active)
    {
        if (!$this->branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage payment methods.', 403);
        }

        $method = PaymentMethodAccount::findOrFail($id);
        $method->is_active = $active;
        $method->updated_by = $request->user()?->id;
        $method->save();

        return ApiResponse::success(
            ['payment_method' => $this->present($method->fresh('account.type'), true)],
            $active ? 'Payment method activated.' : 'Payment method deactivated.'
        );
    }

    private function assertAssetAccount(int $accountId)
    {
        $account = Account::with('type:id,code,name')->find($accountId);

        if (!$account || !$account->is_active) {
            return ApiResponse::error('Payment methods can only be mapped to an active account.', 422);
        }
        if (optional($account->type)->code !== 'ASSET') {
            return ApiResponse::error('Payment methods can only be mapped to an active asset account.', 422);
        }

        return null;
    }

    private function present(PaymentMethodAccount $m, bool $admin = false): array
    {
        $payload = [
            'id'                  => $m->id,
            'method'              => $m->method,
            'display_name'        => $m->presentation_name,
            'account_id'          => $m->account_id,
            'account_code'        => optional($m->account)->code,
            'account_name'        => optional($m->account)->name,
            'affects_cash_drawer' => (bool) $m->affects_cash_drawer,
            'is_active'           => (bool) $m->is_active,
            'sort_order'          => (int) $m->sort_order,
            'icon_key'            => $m->icon_key,
        ];

        if ($admin) {
            $payload['branch_id']    = $m->branch_id;
            $payload['is_inherited'] = (bool) $m->is_inherited;
        }

        return $payload;
    }
}
