<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $q = Grade::query()->orderBy('sort_order')->orderBy('min_oil_pct');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('code', 'like', "%$s%")->orWhere('name', 'like', "%$s%"));
        }
        if ($request->boolean('global')) {
            $q->whereNull('company_id');
        } elseif ($companyId) {
            $q->where(fn ($qq) => $qq->whereNull('company_id')->orWhere('company_id', $companyId));
        }
        if ($request->filled('material_type')) $q->where('material_type', $request->material_type);

        return response()->json($q->paginate($request->get('per_page', 50)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:100',
            'material_type' => 'nullable|string|max:50',
            'min_oil_pct' => 'nullable|numeric|min:0|max:100',
            'max_oil_pct' => 'nullable|numeric|min:0|max:100|gte:min_oil_pct',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:ACTIVE,INACTIVE',
        ]);
        $data['company_id'] = $this->activeCompanyId();

        $grade = Grade::create($data);
        return response()->json($grade, 201);
    }

    public function show(Grade $grade)
    {
        return response()->json($grade);
    }

    public function update(Request $request, Grade $grade)
    {
        $data = $request->validate([
            'code' => 'sometimes|string|max:30',
            'name' => 'sometimes|string|max:100',
            'material_type' => 'nullable|string|max:50',
            'min_oil_pct' => 'nullable|numeric|min:0|max:100',
            'max_oil_pct' => 'nullable|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:ACTIVE,INACTIVE',
        ]);
        $grade->update($data);
        return response()->json($grade);
    }

    public function destroy(Grade $grade)
    {
        $grade->update(['status' => 'INACTIVE']);
        return response()->json(['success' => true]);
    }
}
