<?php

namespace App\Http\Controllers;

use App\Models\VehicleMovement;
use Illuminate\Http\Request;

class VehicleMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = VehicleMovement::with(['vehicle', 'branch', 'vendor', 'item'])
            ->orderByDesc('date')->orderByDesc('id');

        $this->scopeBranches($query);

        if ($request->filled('vehicle_id')) $query->where('vehicle_id', $request->vehicle_id);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('transaction_type')) $query->where('transaction_type', $request->transaction_type);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('date', '<=', $request->date_to);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('reference_number', 'like', "%$s%")
                ->orWhere('lot_number', 'like', "%$s%"));
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'transaction_type' => ['required', 'in:SETTLEMENT_BILL,STOCK_IN,STOCK_OUT,STOCK_TRANSFER,MANUAL'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'customer' => ['nullable', 'string', 'max:200'],
            'item_id' => ['nullable', 'exists:items,id'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['created_by'] = $request->user()->id;
        $mv = VehicleMovement::create($data);
        return response()->json($mv->load(['vehicle', 'branch', 'vendor', 'item']), 201);
    }
}
