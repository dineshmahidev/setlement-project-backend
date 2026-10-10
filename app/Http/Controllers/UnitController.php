<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $query = Unit::orderBy('name');
        if ($request->boolean('all')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:units,name'],
            'code' => ['required', 'string', 'max:20', 'unique:units,code'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);
        $unit = Unit::create($data);
        $this->audit->log('CREATE', 'Unit', $unit->id, null, $unit->toArray());
        return response()->json($unit, 201);
    }

    public function show(Unit $unit)
    {
        return response()->json($unit);
    }

    public function update(Request $request, Unit $unit)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', 'unique:units,name,'.$unit->id],
            'code' => ['sometimes', 'string', 'max:20', 'unique:units,code,'.$unit->id],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);
        $old = $unit->toArray();
        $unit->update($data);
        $this->audit->log('UPDATE', 'Unit', $unit->id, $old, $unit->toArray());
        return response()->json($unit);
    }

    public function destroy(Unit $unit)
    {
        $unit->update(['status' => 'INACTIVE']);
        return response()->json(['message' => 'Unit archived']);
    }
}
