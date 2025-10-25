<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'full_name'  => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: null,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'status'     => $this->status,
            'address'    => $this->address,
            'meta'       => $this->meta, // assuming cast to array/json in model
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
