<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function store(Request $request, $saleId)
    {
        $sale = Sale::findOrFail($saleId);

        $data = $request->validate([
            'amount'   => 'required|numeric|min:1',
            'method'   => 'required|in:cash,card,bank,wallet',
            'reference'=> 'nullable|string',
            'received_by' => 'nullable|integer',
            'received_on' => 'nullable|date'
        ]);

        $payment = $sale->payments()->create($data);

        // update sale status
        $paid = $sale->payments()->sum('amount');
        if ($paid >= $sale->total) {
            $sale->update(['status' => 'paid']);
        } elseif ($paid > 0) {
            $sale->update(['status' => 'partial']);
        } else {
            $sale->update(['status' => 'pending']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment added successfully',
            'data'    => [
                'sale'    => $sale->fresh()->load('payments'),
                'payment' => $payment
            ]
        ]);
    }

}
