<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        $user = request()->user();
        $allowed = $user->allowedCompanyIds();

        $query = Company::query()->active()->orderBy('sort_order')->orderBy('company_name');

        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($company) => $this->transform($company)),
        ]);
    }

    /** Admin: list all (including inactive) for management. */
    public function all(): JsonResponse
    {
        $this->guardAdmin();
        return response()->json([
            'data' => Company::query()->orderBy('sort_order')->orderBy('company_name')->get()->map(fn ($c) => $this->transform($c)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guardAdmin();
        $data = $request->validate([
            'code' => 'required|string|max:30|unique:companies,code',
            'company_name' => 'required|string|max:150',
            'short_name' => 'nullable|string|max:60',
            'trade_name' => 'nullable|string|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:5',
            'pincode' => 'nullable|string|max:12',
            'gstin' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);
        $data['slug'] = Str::slug($request->slug ?: $data['code']);
        $company = Company::create($data);

        return response()->json(['data' => $this->transform($company)], 201);
    }

    public function show(Company $company): JsonResponse
    {
        $allowed = request()->user()->allowedCompanyIds();

        if ($allowed !== null && ! in_array((int) $company->id, array_map('intval', $allowed), true)) {
            abort(403, 'You do not have access to this company.');
        }

        return response()->json(['data' => $this->transform($company)]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->guardAdmin();
        $data = $request->validate([
            'company_name' => 'sometimes|string|max:150',
            'short_name' => 'nullable|string|max:60',
            'trade_name' => 'nullable|string|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:5',
            'pincode' => 'nullable|string|max:12',
            'gstin' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);
        $company->update($data);

        return response()->json(['data' => $this->transform($company)]);
    }

    /** Deactivate (soft) — never deletes; stock/history preserved. */
    public function destroy(Company $company): JsonResponse
    {
        $this->guardAdmin();
        $company->update(['status' => 'inactive']);
        return response()->json(['success' => true, 'message' => 'Company deactivated.']);
    }

    private function guardAdmin(): void
    {
        $user = request()->user();
        $role = strtoupper($user->role->name ?? '');
        if (! $user->is_super_admin && ! str_contains($role, 'ADMIN')) {
            abort(403, 'Only administrators can manage companies.');
        }
    }

    private function transform(Company $company): array
    {
        return [
            'id' => $company->id,
            'code' => $company->code,
            'slug' => $company->slug,
            'company_name' => $company->company_name,
            'trade_name' => $company->trade_name,
            'short_name' => $company->short_name,
            'address' => $company->address,
            'city' => $company->city,
            'state' => $company->state,
            'pincode' => $company->pincode,
            'gstin' => $company->gstin,
            'phone' => $company->phone,
            'email' => $company->email,
            'status' => $company->status,
            'branches_count' => $company->branches()->count(),
        ];
    }
}
