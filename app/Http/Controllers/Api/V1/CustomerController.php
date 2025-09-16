<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Response\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $perPage = request('per_page')?:20;
        $query = Customer::query();
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")
                    ->orWhere('phone', 'like', "%$search%");
            });
        }
        // 📄 Custom pagination with skip & take
        $page = (int) $request->get('page', 1);   // default: page 1
        $perPage = (int) $request->get('per_page', 15); // default: 15

        $total = $query->count();

        $skip = ($page - 1) * $perPage;
        $customers = $query
            ->skip($skip)
            ->take($perPage)
            ->get();

        $data[] = [
            'customers' => CustomerResource::collection($customers),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
        ];
        return ApiResponse::success($data, 'Customers fetched successfully');
    }

    public function store(CustomerRequest $request)
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $customer = Customer::create($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer created successfully');
    }

    public function show(Customer $customer)
    {
        return ApiResponse::success(new CustomerResource($customer), 'Customer details fetched successfully');
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $customer->update($data);
        return ApiResponse::success(new CustomerResource($customer), 'Customer updated successfully');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return ApiResponse::success(null, 'Customer deleted successfully');
    }
}
