<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorRequest;
use App\Http\Resources\VendorResource;
use App\Http\Response\ApiResponse;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $perPage = request('per_page')?:20;
        $query = Vendor::query();
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
        $vendors = $query
            ->skip($skip)
            ->take($perPage)
            ->get();

        $data[] = [
            'vendors' => VendorResource::collection($vendors),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
        ];
        return ApiResponse::success($data, 'Vendors fetched successfully');
    }

    public function store(VendorRequest $request)
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $vendor = Vendor::create($data);
        return ApiResponse::success(new VendorResource($vendor), 'Vendor created successfully');
    }

    public function show(Vendor $vendor)
    {
        return ApiResponse::success(new VendorResource($vendor), 'Vendor details fetched successfully');
    }

    public function update(VendorRequest $request, Vendor $vendor)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $vendor->update($data);
        return ApiResponse::success(new VendorResource($vendor), 'Vendor updated successfully');
    }

    public function destroy(Vendor $vendor)
    {
        $vendor->delete();
        return ApiResponse::success(null, 'Vendor deleted successfully');
    }
}
