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
        // Finance fields come from the controller select (leftJoinSub aggregates).
        // Default to 0.0 / null if not present.
        $totalSales        = (float) ($this->total_sales        ?? 0);
        $totalReceipts     = (float) ($this->total_receipts     ?? 0);
        $remainingBalance  = (float) ($this->remaining_balance  ?? ($totalSales - $totalReceipts));
        $lastActivityAt    = $this->last_activity_at ? (string) $this->last_activity_at : null;

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

            // Finance
            'total_sales'        => round($totalSales, 2),
            'total_receipts'     => round($totalReceipts, 2),
            'remaining_balance'  => round($remainingBalance, 2),
            'last_activity_at'   => $lastActivityAt, // e.g. "2025-10-10 14:32:00"

            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
