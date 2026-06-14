<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'max:80', "unique:users,email,{$id}"],
            'phone' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'min:6'],
            'is_active' => ['boolean'],

            // Frontend should send role_id or role_ids. `roles` is kept for old clients.
            'role_id' => ['sometimes', 'nullable'],
            'role_ids' => ['sometimes', 'array', 'min:1', 'max:1'],
            'role_ids.*' => ['required'],
            'roles' => ['sometimes', 'array', 'min:1', 'max:1'],
            'roles.*' => ['required'],
        ];
    }
}
