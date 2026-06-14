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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['nullable', 'string', 'in:xlsx,pdf,json'],
            'orientation' => ['nullable', 'string', 'in:portrait,landscape'],
        ];
    }
}
