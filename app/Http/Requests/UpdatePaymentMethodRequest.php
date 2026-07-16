<?php

namespace App\Http\Requests;

use App\Services\BranchContextService;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(BranchContextService::class)->isMasterAdmin($this->user());
    }

    public function rules(): array
    {
        // NOTE: `method` (machine code) is intentionally NOT updatable — used
        // codes must remain immutable so historical records stay readable.
        return [
            'display_name'        => ['sometimes', 'string', 'max:60'],
            'account_id'          => ['sometimes', 'integer', 'exists:accounts,id'],
            'is_active'           => ['sometimes', 'boolean'],
            'affects_cash_drawer' => ['sometimes', 'boolean'],
            'sort_order'          => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'icon_key'            => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
