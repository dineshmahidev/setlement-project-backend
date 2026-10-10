<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('branch_name', 'like', "%{$search}%")
                  ->orWhere('branch_code', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('gstin', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('all')) {
            return response()->json([
                'success' => true,
                'data' => $query->orderBy('branch_name')->get(),
            ]);
        }

        $branches = $query->orderBy('branch_name')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }

    public function store(BranchRequest $request)
    {
        $branch = Branch::create($request->validated());

        AuditLog::logAction('created', 'Branch', $branch->id, $branch->branch_code, null, $branch->toArray(), 'Branch created');

        return response()->json([
            'success' => true,
            'message' => 'Branch created successfully',
            'data' => $branch,
        ], 201);
    }

    public function show(Branch $branch)
    {
        $branch->loadCount(['settlements', 'invoices', 'debitNotes']);

        return response()->json([
            'success' => true,
            'data' => $branch,
        ]);
    }

    public function update(BranchRequest $request, Branch $branch)
    {
        $oldValues = $branch->toArray();
        $branch->update($request->validated());

        AuditLog::logAction('updated', 'Branch', $branch->id, $branch->branch_code, $oldValues, $branch->toArray(), 'Branch updated');

        return response()->json([
            'success' => true,
            'message' => 'Branch updated successfully',
            'data' => $branch,
        ]);
    }

    public function destroy(Branch $branch)
    {
        if ($branch->settlements()->exists() || $branch->invoices()->exists() || $branch->debitNotes()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete branch with associated settlements or financial records. Deactivate it instead.',
            ], 422);
        }

        $old = $branch->toArray();
        $branch->delete();

        AuditLog::logAction('deleted', 'Branch', $branch->id, $branch->branch_code, $old, null, 'Branch deleted');

        return response()->json([
            'success' => true,
            'message' => 'Branch deleted successfully',
        ]);
    }
}
