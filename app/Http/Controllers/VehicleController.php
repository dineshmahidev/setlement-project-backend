<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\AuditService;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $query = Vehicle::with('branch')->orderBy('number');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('number', 'like', "%$s%")->orWhere('driver_name', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($ids = $this->allowedBranchIds()) {
            $query->where(fn ($q) => $q->whereIn('branch_id', $ids)->orWhereNull('branch_id'));
        }
        if ($request->boolean('all')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $vehicle = Vehicle::create($data);
        $this->audit->log('CREATE', 'Vehicle', $vehicle->id, null, $vehicle->toArray());
        return response()->json($vehicle, 201);
    }

    public function show(Vehicle $vehicle)
    {
        return response()->json($vehicle->load('branch'));
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $data = $this->validated($request, $vehicle);
        $old = $vehicle->toArray();
        $vehicle->update($data);
        $this->audit->log('UPDATE', 'Vehicle', $vehicle->id, $old, $vehicle->toArray());
        return response()->json($vehicle);
    }

    public function destroy(Vehicle $vehicle)
    {
        $this->audit->log('ARCHIVE', 'Vehicle', $vehicle->id, $vehicle->toArray(), ['status' => 'INACTIVE']);
        $vehicle->update(['status' => 'INACTIVE']);
        return response()->json(['message' => 'Vehicle archived']);
    }

    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $unique = $vehicle ? ','.$vehicle->id : '';
        return $request->validate([
            'number' => ['required', 'string', 'max:20', 'unique:vehicles,number'.$unique],
            'type' => ['nullable', 'string', 'max:50'],
            'owner_name' => ['nullable', 'string', 'max:150'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'rc_number' => ['nullable', 'string', 'max:50'],
            'insurance_expiry' => ['nullable', 'date'],
            'permit_expiry' => ['nullable', 'date'],
            'fitness_expiry' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);
    }
}
