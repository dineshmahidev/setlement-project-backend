<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorRequest;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Services\GstService;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    protected $gstService;

    public function __construct(GstService $gstService)
    {
        $this->gstService = $gstService;
    }

    public function index(Request $request)
    {
        $query = $this->scopeCompany(Vendor::query());

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('vendor_name', 'like', "%{$search}%")
                  ->orWhere('vendor_code', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%")
                  ->orWhere('gstin', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('pan', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status !== 'all') {
                $query->where('status', $request->status);
            }
        } else {
            $query->where('status', 'active');
        }

        if ($request->boolean('all')) {
            return response()->json([
                'success' => true,
                'data' => $query->orderBy('vendor_name')->get(),
            ]);
        }

        $vendors = $query->orderBy('vendor_name')->paginate($request->get('per_page', 20));

        // Append current balance to each vendor
        $vendors->getCollection()->transform(function ($vendor) {
            $vendor->current_balance = $vendor->current_balance;
            return $vendor;
        });

        return response()->json([
            'success' => true,
            'data' => $vendors,
        ]);
    }

    public function search(Request $request)
    {
        $term = $request->get('q', '');
        $vendors = $this->scopeCompany(Vendor::query())
            ->where('status', 'active')
            ->where(function ($q) use ($term) {
                $q->where('vendor_name', 'like', "%{$term}%")
                  ->orWhere('vendor_code', 'like', "%{$term}%")
                  ->orWhere('business_name', 'like', "%{$term}%")
                  ->orWhere('gstin', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%");
            })
            ->limit(15)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $vendors,
        ]);
    }

    public function lookupGstin(Request $request)
    {
        $request->validate([
            'gstin' => 'required|string|min:15|max:15',
        ]);

        $details = $this->gstService->lookup($request->gstin);

        return response()->json([
            'success' => true,
            'data' => $details,
        ]);
    }

    public function store(VendorRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = $this->activeCompanyId();

        if (empty($data['vendor_code'])) {
            $code = 'VEND-' . random_int(100, 999);
            while (Vendor::where('vendor_code', $code)->exists()) {
                $code = 'VEND-' . random_int(1000, 99999);
            }
            $data['vendor_code'] = $code;
        }

        $vendor = Vendor::create($data);

        AuditLog::logAction('created', 'Vendor', $vendor->id, $vendor->vendor_code, null, $vendor->toArray(), 'Vendor created');

        return response()->json([
            'success' => true,
            'message' => 'Vendor created successfully',
            'data' => $vendor,
        ], 201);
    }

    public function show(Vendor $vendor)
    {
        $this->guardCompanyOwnership($vendor);
        $vendor->loadCount(['settlements', 'invoices', 'debitNotes']);
        $vendor->current_balance = $vendor->current_balance;

        return response()->json([
            'success' => true,
            'data' => $vendor,
        ]);
    }

    public function update(VendorRequest $request, Vendor $vendor)
    {
        $this->guardCompanyOwnership($vendor);
        $old = $vendor->toArray();
        $vendor->update($request->validated());

        AuditLog::logAction('updated', 'Vendor', $vendor->id, $vendor->vendor_code, $old, $vendor->toArray(), 'Vendor updated');

        return response()->json([
            'success' => true,
            'message' => 'Vendor updated successfully',
            'data' => $vendor,
        ]);
    }

    public function toggleStatus(Request $request, Vendor $vendor)
    {
        $this->guardCompanyOwnership($vendor);
        $newStatus = $request->input('status', $vendor->status === 'active' ? 'inactive' : 'active');
        if (! in_array($newStatus, ['active', 'inactive'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid status'], 422);
        }

        $old = $vendor->toArray();
        $vendor->update(['status' => $newStatus]);

        AuditLog::logAction('status_changed', 'Vendor', $vendor->id, $vendor->vendor_code, $old, $vendor->toArray(), "Vendor marked {$newStatus}");

        return response()->json([
            'success' => true,
            'message' => "Vendor is now {$newStatus}",
            'data' => $vendor,
        ]);
    }

    public function destroy(Vendor $vendor)
    {
        $this->guardCompanyOwnership($vendor);

        $hasLinkedRecords = $vendor->settlements()->exists()
            || $vendor->invoices()->exists()
            || $vendor->debitNotes()->exists()
            || \App\Models\Inward::where('vendor_id', $vendor->id)->exists()
            || \App\Models\Outward::where('party_id', $vendor->id)->exists()
            || \App\Models\Lot::where('vendor_id', $vendor->id)->exists()
            || \App\Models\InventoryTransaction::where('vendor_id', $vendor->id)->orWhere('party_id', $vendor->id)->exists();

        if ($hasLinkedRecords) {
            return response()->json([
                'success' => false,
                'has_transactions' => true,
                'message' => 'Cannot delete vendor with existing transactions or records. You can set status to inactive instead.',
            ], 422);
        }

        try {
            $old = $vendor->toArray();
            $vendor->delete();

            AuditLog::logAction('deleted', 'Vendor', $vendor->id, $vendor->vendor_code, $old, null, 'Vendor deleted');

            return response()->json([
                'success' => true,
                'message' => 'Vendor deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'has_transactions' => true,
                'message' => 'Cannot delete vendor with linked records. Please set status to inactive instead.',
            ], 422);
        }
    }
}
