<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
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
            'email'      => 'nullable|email|unique:customers,email,' . $this->id,
            'phone'      => 'required|string|max:20|unique:customers,phone,' . $this->id,
            'address'      => 'string|nullable',
            // 'password'   => $this->isMethod('post') ? 'required|string|min:6' : 'nullable|string|min:6',
            'password'   => $this->isMethod('post') ? 'nullable|string|min:6' : 'nullable|string|min:6',
            'status'     => 'in:active,inactive,blocked',
            'meta'       => 'nullable|array',
        ];
    }
}
