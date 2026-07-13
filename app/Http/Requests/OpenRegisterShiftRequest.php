<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenRegisterShiftRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('open-register-shift') ?? false; }
    public function rules(): array { return [
        'register_id' => ['required', 'integer', 'exists:registers,id'],
        'client_ref' => ['required', 'uuid'],
        'opening_cash' => ['required', 'numeric', 'min:0'],
        'opening_note' => ['nullable', 'string', 'max:2000'],
        'device_identifier' => ['nullable', 'string', 'max:191'],
        'opened_at' => ['nullable', 'date'],
    ]; }
}
