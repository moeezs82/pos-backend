<?php

namespace App\Http\Requests;

use App\Models\PaymentMethodAccount;
use App\Services\BranchContextService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(BranchContextService::class)->isMasterAdmin($this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('method')) {
            $this->merge(['method' => PaymentMethodAccount::normalizeCode($this->input('method'))]);
        }
    }

    public function rules(): array
    {
        $branchId = (int) $this->input('branch_id');

        return [
            'branch_id'           => ['required', 'integer', 'exists:branches,id'],
            // Immutable machine code, unique per branch.
            'method'              => [
                'required', 'string', 'max:40', 'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('payment_method_accounts', 'method')->where(fn ($q) => $q->where('branch_id', $branchId)),
            ],
            'display_name'        => ['required', 'string', 'max:60'],
            'account_id'          => ['required', 'integer', 'exists:accounts,id'],
            'is_active'           => ['nullable', 'boolean'],
            'affects_cash_drawer' => ['nullable', 'boolean'],
            'sort_order'          => ['nullable', 'integer', 'min:0', 'max:9999'],
            'icon_key'            => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'method.regex'  => 'The machine code may only contain lowercase letters, numbers, hyphens and underscores.',
            'method.unique' => 'This payment method already exists for the selected branch.',
        ];
    }
}
