<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'salesman_id' => ['nullable', 'integer', 'exists:users,id'],
            'delivery_boy_id' => ['nullable', 'integer', 'exists:users,id'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', 'string', 'max:50'],
            'sale_type' => ['nullable', 'string', 'max:50'],
            'stock_type' => ['nullable', 'string', 'max:50'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'party_type' => ['nullable', 'string', 'in:customer,vendor'],
            'party_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort_by' => ['nullable', 'string', 'max:80'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            // The Enterprise Reports export screen deliberately requests
            // per_page=1000 to pull the full filtered dataset in one shot
            // (see enterprise_reports_workspace_screen.dart). Capping this at
            // 500 made every export request fail Form Request validation —
            // and because a failed FormRequest validation redirects (via
            // url()->previous(), which falls back to the site root when
            // there's no Referer header, as our HTTP client never sends
            // one), the export silently landed on the Laravel welcome page
            // instead of returning a 422. Raised to 5000 to give headroom
            // above what the export screen currently sends.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'format' => ['nullable', 'string', 'in:xlsx,pdf,json'],
            'orientation' => ['nullable', 'string', 'in:portrait,landscape'],
        ];
    }
}
