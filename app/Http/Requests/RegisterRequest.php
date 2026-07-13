<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-register-shifts') ?? false;
    }

    public function rules(): array
    {
        $branchId = (int) $this->user()->branch_id;
        $registerId = $this->route('register')?->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('registers', 'code')->where('branch_id', $branchId)->ignore($registerId),
            ],
            'device_identifier' => ['nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
