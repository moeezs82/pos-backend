<?php

namespace App\Http\Requests;

use App\Support\ProtectedPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:120'],
            'guard_name' => ['nullable', 'string', 'in:web,api'],
            'permissions' => ['sometimes', 'array'],
            // Master-Admin-only permissions can never be assigned to a branch
            // role — rejected with a clear 422 instead of silently dropping.
            'permissions.*' => ['string', Rule::notIn(ProtectedPermissions::masterOnly())],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.not_in' => 'This permission is reserved for Master Admin and cannot be assigned to a branch role.',
        ];
    }
}
