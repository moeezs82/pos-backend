<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\PaymentMethodAccount;
use App\Services\BranchContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private const CORE_ACCOUNT_CODES = [
        '1000', '1010', '1200', '1210', '1400',
        '2000', '2100', '2105', '2205', '3100',
        '4000', '5100', '5205',
    ];

    public function getTypes()
    {
        $rows = AccountType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'is_active'   => 'nullable|boolean',
            'type_code'   => 'nullable|in:ASSET,LIABILITY,EQUITY,INCOME,EXPENSE',
            'q'           => 'nullable|string|max:100',
            'per_page'    => 'nullable|integer|min:5|max:100',
        ]);

        $q = Account::query()
            ->with('type:id,code,name')
            ->select(['id', 'code', 'name', 'account_type_id', 'is_active'])
            ->when(isset($data['is_active']), fn($qq) => $qq->where('is_active', (int)$data['is_active']))
            ->when($data['type_code'] ?? null, function ($qq, $code) {
                $qq->whereHas('type', fn($t) => $t->where('code', $code));
            })
            ->when($data['q'] ?? null, function ($qq, $term) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
                $qq->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)->orWhere('code', 'like', $like);
                });
            })
            ->orderBy('code');

        // If you prefer non-paginated for dropdowns:
        if (! $request->has('per_page')) {
            return response()->json([
                'success' => true,
                'data'   => $q->get()->map(function ($a) {
                    return [
                        'id'   => $a->id,
                        'code' => $a->code,
                        'name' => $a->name,
                        'type' => $a->type?->code,
                        'is_active' => (bool)$a->is_active,
                    ];
                }),
            ]);
        }

        $perPage = (int)($data['per_page'] ?? 25);
        $page = $q->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'   => [
                'items' => $page->getCollection()->map(function ($a) {
                    return [
                        'id'   => $a->id,
                        'code' => $a->code,
                        'name' => $a->name,
                        'type' => $a->type?->code,
                        'is_active' => (bool)$a->is_active,
                    ];
                }),
                'pagination' => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ],
        ]);
    }

    public function paymentMappings(Request $request, BranchContextService $branches)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage payment account mappings.', 403);
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $mappings = PaymentMethodAccount::query()
            ->with('account:id,code,name,account_type_id,is_active')
            ->where('branch_id', (int) $data['branch_id'])
            ->orderBy('method')
            ->get()
            ->map(fn (PaymentMethodAccount $mapping) => [
                'id'           => $mapping->id,
                'method'       => $mapping->method,
                'account_id'   => $mapping->account_id,
                'is_inherited' => (bool) $mapping->is_inherited,
                'account'      => $mapping->account,
            ]);

        return ApiResponse::success(['mappings' => $mappings]);
    }

    public function updatePaymentMapping(
        Request $request,
        BranchContextService $branches,
        int $branchId,
        string $method
    ) {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage payment account mappings.', 403);
        }

        $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        abort_unless(DB::table('branches')->where('id', $branchId)->exists(), 404);
        // Accept any well-formed machine code so dynamically configured methods
        // (KNET, cheque, ...) can be remapped, not just the legacy four.
        abort_unless((bool) preg_match('/^[a-z0-9_\-]+$/', $method), 404);

        $account = Account::query()
            ->with('type:id,code,name')
            ->whereKey($request->integer('account_id'))
            ->firstOrFail();
        if (!$account->is_active) {
            return ApiResponse::error('Payment methods can only be mapped to an active account.', 422);
        }
        if ($account->type?->code !== 'ASSET') {
            return ApiResponse::error('Payment methods can only be mapped to an active asset account.', 422);
        }

        $mapping = PaymentMethodAccount::updateOrCreate(
            ['method' => $method, 'branch_id' => $branchId],
            ['account_id' => $account->id, 'is_inherited' => false]
        );

        return ApiResponse::success(
            $mapping->load('account:id,code,name,account_type_id,is_active'),
            'Branch payment account mapping updated.'
        );
    }

    public function store(Request $request, BranchContextService $branches)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can create accounts.', 403);
        }

        $v = $request->validate([
            'code'            => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')],
            'name'            => 'required|string|max:120',
            'account_type_id' => 'required|exists:account_types,id',
            'is_active'       => 'boolean',
        ]);
        $v['code'] = strtoupper(trim($v['code']));
        $a = \App\Models\Account::create($v + ['is_active' => $v['is_active'] ?? 1]);
        return response()->json(['success' => true, 'data' => $a], 201);
    }

    public function show($id)
    {
        $a = \App\Models\Account::with('type')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $a]);
    }

    public function update(Request $request, BranchContextService $branches, $id)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can update accounts.', 403);
        }

        $a = \App\Models\Account::findOrFail($id);
        $v = $request->validate([
            'code'            => ['sometimes', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($a->id)],
            'name'            => 'sometimes|string|max:120',
            'account_type_id' => 'sometimes|exists:account_types,id',
            'is_active'       => 'sometimes|boolean',
        ]);

        if (array_key_exists('code', $v)) {
            $v['code'] = strtoupper(trim($v['code']));
        }

        if ($this->isCoreAccount($a)) {
            if (isset($v['code']) && $v['code'] !== $a->code) {
                return ApiResponse::error('Core account codes cannot be changed because posting services depend on them.', 422);
            }
            if (isset($v['account_type_id']) && (int) $v['account_type_id'] !== (int) $a->account_type_id) {
                return ApiResponse::error('The type of a core account cannot be changed.', 422);
            }
            if (array_key_exists('is_active', $v) && !$v['is_active']) {
                return ApiResponse::error('Core accounts cannot be deactivated.', 422);
            }
        } elseif ($this->hasFinancialUsage($a)) {
            if (isset($v['code']) && $v['code'] !== $a->code) {
                return ApiResponse::error('An account code cannot be changed after financial activity has been posted to it.', 422);
            }
            if (isset($v['account_type_id']) && (int) $v['account_type_id'] !== (int) $a->account_type_id) {
                return ApiResponse::error('An account type cannot be changed after financial activity has been posted to it.', 422);
            }
        }

        $a->update($v);
        return response()->json(['success' => true, 'data' => $a]);
    }

    public function activate(Request $request, BranchContextService $branches, $id)
    {
        return $this->toggleActive($request, $branches, $id, true);
    }
    public function deactivate(Request $request, BranchContextService $branches, $id)
    {
        return $this->toggleActive($request, $branches, $id, false);
    }

    protected function toggleActive(Request $request, BranchContextService $branches, $id, bool $active)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can change account status.', 403);
        }

        $a = \App\Models\Account::findOrFail($id);
        if (!$active && $this->isCoreAccount($a)) {
            return ApiResponse::error('Core accounts cannot be deactivated.', 422);
        }

        $a->update(['is_active' => $active ? 1 : 0]);
        return response()->json(['success' => true, 'data' => ['id' => $a->id, 'is_active' => (bool)$a->is_active]]);
    }

    private function isCoreAccount(Account $account): bool
    {
        return in_array((string) $account->code, self::CORE_ACCOUNT_CODES, true);
    }

    private function hasFinancialUsage(Account $account): bool
    {
        return DB::table('journal_postings')->where('account_id', $account->id)->exists()
            || DB::table('payment_method_accounts')->where('account_id', $account->id)->exists();
    }
}
