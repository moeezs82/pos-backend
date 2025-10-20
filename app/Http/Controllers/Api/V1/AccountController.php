<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Http\Request;

class AccountController extends Controller
{
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

    public function store(Request $request)
    {
        $v = $request->validate([
            'code'            => 'required|string|max:20|unique:accounts,code',
            'name'            => 'required|string|max:120',
            'account_type_id' => 'required|exists:account_types,id',
            'is_active'       => 'boolean',
        ]);
        $a = \App\Models\Account::create($v + ['is_active' => $v['is_active'] ?? 1]);
        return response()->json(['success' => true, 'data' => $a], 201);
    }

    public function show($id)
    {
        $a = \App\Models\Account::with('type')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $a]);
    }

    public function update(Request $request, $id)
    {
        $a = \App\Models\Account::findOrFail($id);
        $v = $request->validate([
            'code'            => "sometimes|string|max:20|unique:accounts,code,{$id}",
            'name'            => 'sometimes|string|max:120',
            'account_type_id' => 'sometimes|exists:account_types,id',
            'is_active'       => 'sometimes|boolean',
        ]);
        $a->update($v);
        return response()->json(['success' => true, 'data' => $a]);
    }

    public function activate($id)
    {
        return $this->toggleActive($id, true);
    }
    public function deactivate($id)
    {
        return $this->toggleActive($id, false);
    }

    protected function toggleActive($id, bool $active)
    {
        $a = \App\Models\Account::findOrFail($id);
        $a->update(['is_active' => $active ? 1 : 0]);
        return response()->json(['success' => true, 'data' => ['id' => $a->id, 'is_active' => (bool)$a->is_active]]);
    }
}
