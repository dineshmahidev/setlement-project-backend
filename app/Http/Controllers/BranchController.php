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
        $query = Branch::query()->with('company');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        } else {
            $query = $this->scopeCompany($query);
        }
        $query = $this->scopeBranches($query, 'id');

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
        $data = $request->validated();
        $data['company_id'] = $this->activeCompanyId();

        $branch = Branch::create($data);

        AuditLog::logAction('created', 'Branch', $branch->id, $branch->branch_code, null, $branch->toArray(), 'Branch created');

        return response()->json([
            'success' => true,
            'message' => 'Branch created successfully',
            'data' => $branch,
        ], 201);
    }

    public function show(Branch $branch)
    {
        $this->guardCompanyOwnership($branch);
        $this->guardBranchOwnership((object) ['branch_id' => $branch->id]);

        $branch->loadCount(['settlements', 'invoices', 'debitNotes']);

        return response()->json([
            'success' => true,
            'data' => $branch,
        ]);
    }

    public function update(BranchRequest $request, Branch $branch)
    {
        $this->guardCompanyOwnership($branch);
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
        $this->guardCompanyOwnership($branch);

        $old = $branch->toArray();
        $name = $branch->branch_name;
        $code = $branch->branch_code;

        // Automatically cascades and deletes all related records via Branch model deleting hook
        $branch->delete();

        AuditLog::logAction('deleted', 'Branch', $branch->id, $code, $old, null, "Godown '{$name}' and all associated records permanently deleted");

        return response()->json([
            'success' => true,
            'message' => "Godown '{$name}' and all associated data deleted successfully",
        ]);
    }
}
