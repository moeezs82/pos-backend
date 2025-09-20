<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorRequest extends FormRequest
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
        return [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'tax_id'  => 'nullable|string|max:100',
            'company_name'  => 'nullable|string|max:100',
            'email'      => 'nullable|email|unique:vendors,email,' . $this->id,
            'phone'      => 'required|string|max:20|unique:vendors,phone,' . $this->id,
            'address'      => 'string|nullable',
            'password'   => 'nullable|string|min:6',
            'status'     => 'in:active,inactive',
        ];
    }
}
