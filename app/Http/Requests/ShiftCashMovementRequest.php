<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShiftCashMovementRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('record-shift-cash-movement') ?? false; }
    public function rules(): array { return [
        'client_ref' => ['required', 'uuid'], 'direction' => ['required', 'in:in,out'],
        'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:191'],
        'note' => ['nullable', 'string', 'max:2000'], 'occurred_at' => ['nullable', 'date'],
    ]; }
}
