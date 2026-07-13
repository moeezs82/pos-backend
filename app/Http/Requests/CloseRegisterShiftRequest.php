<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseRegisterShiftRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('close-own-register-shift') || $this->user()?->can('manage-register-shifts'); }
    public function rules(): array { return [
        'counted_cash' => ['required', 'numeric', 'min:0'],
        'closing_note' => ['nullable', 'string', 'max:2000'],
        'pending_sync_count' => ['nullable', 'integer', 'min:0'],
        'accept_pending_sync' => ['nullable', 'boolean'],
        'approval_note' => ['nullable', 'string', 'max:2000'],
        'client_ref' => ['required', 'uuid'],
    ]; }
}
