<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function store(Request $request, $saleId)
    {
        $sale = Sale::findOrFail($saleId);

        $data = $request->validate([
            'amount'      => 'required|numeric|min:1',
            'method'      => ['required', Rule::in(['cash', 'card', 'bank', 'wallet'])],
            'reference'   => 'nullable|string',
            'received_by' => 'nullable|integer',
            'received_on' => 'nullable|date'
        ]);

        return DB::transaction(function () use ($sale, $data) {
            $payment = $sale->payments()->create($data);
            $this->updateSaleStatus($sale);


            return ApiResponse::success(
                ['payment' => $payment],
                'Payment added successfully'
            );
        });
    }

    public function update(Request $request, $saleId, $paymentId)
    {
        $sale    = Sale::findOrFail($saleId);
        $payment = $sale->payments()->findOrFail($paymentId);

        $data = $request->validate([
            'amount'      => 'sometimes|numeric|min:1',
            'method'      => ['sometimes', Rule::in(['cash', 'card', 'bank', 'wallet'])],
            'reference'   => 'nullable|string',
            'received_by' => 'nullable|integer',
            'received_on' => 'nullable|date'
        ]);

        return DB::transaction(function () use ($sale, $payment, $data) {
            $payment->update($data);
            $this->updateSaleStatus($sale);

            return ApiResponse::success(
                ['payment' => $payment],
                'Payment updated successfully'
            );
        });
    }

    public function destroy($saleId, $paymentId)
    {
        $sale    = Sale::findOrFail($saleId);
        $payment = $sale->payments()->findOrFail($paymentId);

        return DB::transaction(function () use ($sale, $payment) {
            $payment->delete();
            $this->updateSaleStatus($sale);

            return ApiResponse::success(
                null,
                'Payment deleted successfully'
            );
        });
    }

    private function updateSaleStatus(Sale $sale): void
    {
        $paid = (float) $sale->payments()->sum('amount');
        if ($paid >= (float)$sale->total && $sale->total > 0) {
            $sale->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $sale->update(['status' => 'partial']);
        } else {
            $sale->update(['status' => 'pending']);
        }
    }
}
