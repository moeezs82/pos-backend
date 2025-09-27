<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
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
        $id = $this->route('user')?->id;
        return [
            'name'      => ['required','string','max:120'],
            'email'     => ['required','max:80',"unique:users,email,{$id}"],
            'phone'     => ['nullable','string','max:50'],
            'branch_id'     => ['nullable','exists:branches,id'],
            'password'  => [$this->isMethod('post') ? 'required' : 'nullable','string','min:6'],
            'is_active' => ['boolean'],
            // Optional: role/permission sync at creation time
            'roles'         => ['sometimes','array'],
            'roles.*'       => ['string']
        ];
    }
}
