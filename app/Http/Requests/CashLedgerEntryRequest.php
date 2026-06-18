<?php

namespace App\Http\Requests;

use App\Enums\CashLedgerCategory;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashLedgerEntryRequest extends FormRequest
{
    /** Friendly UI party aliases mapped to FQCNs. */
    private const PARTY_MAP = [
        'user'     => User::class,
        'customer' => Customer::class,
        'vendor'   => Vendor::class,
    ];

    public function authorize(): bool
    {
        return true; // route middleware (auth:sanctum + permission) handles access
    }

    public function rules(): array
    {
        return [
            'category'       => ['required', Rule::in(CashLedgerCategory::values())],
            'amount'         => ['required', 'numeric', 'gt:0'],
            'txn_date'       => ['nullable', 'date_format:Y-m-d'],
            'method'         => ['nullable', Rule::in(['cash', 'bank', 'card', 'wallet'])],

            // Party is optional; if given, type+id must both be present and valid.
            'party_type'     => ['nullable', Rule::in(array_keys(self::PARTY_MAP))],
            'party_id'       => ['nullable', 'integer', 'required_with:party_type'],

            // Mandatory identity when no party linked (cross-field rule below).
            'reference_name' => ['nullable', 'string', 'max:191'],
            'note'           => ['nullable', 'string', 'max:2000'],

            // OTHER_EXPENSE may override the default 5300 expense account.
            'expense_account_code' => ['nullable', 'string', 'exists:accounts,code'],

            'allow_negative_cash'  => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $hasParty = $this->filled('party_type') && $this->filled('party_id');
            $hasRef   = trim((string) $this->input('reference_name')) !== '';

            if (!$hasParty && !$hasRef) {
                $v->errors()->add(
                    'reference_name',
                    'Provide a reference name when no party (user/customer/vendor) is linked.'
                );
            }
        });
    }

    /** Normalize the validated payload for CashLedgerService::create(). */
    public function toServicePayload(?int $branchId): array
    {
        $data = $this->validated();

        if (!empty($data['party_type'])) {
            $data['party_type'] = self::PARTY_MAP[$data['party_type']];
        }

        $data['branch_id']  = $branchId;
        $data['created_by'] = $this->user()?->id;

        return $data;
    }
}
