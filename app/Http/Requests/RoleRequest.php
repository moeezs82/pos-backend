<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('role')?->id;
        return [
            'name'       => ['required', 'string', 'max:120', "unique:roles,name,{$id}"],
            'guard_name' => ['nullable', 'string', 'in:web,api'], // default to web
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}
