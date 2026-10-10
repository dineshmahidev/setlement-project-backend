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
        $query = Vendor::query();

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
            $query->where('status', $request->status);
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
        $vendors = Vendor::where('status', 'active')
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
        $vendor = Vendor::create($request->validated());

        AuditLog::logAction('created', 'Vendor', $vendor->id, $vendor->vendor_code, null, $vendor->toArray(), 'Vendor created');

        return response()->json([
            'success' => true,
            'message' => 'Vendor created successfully',
            'data' => $vendor,
        ], 201);
    }

    public function show(Vendor $vendor)
    {
        $vendor->loadCount(['settlements', 'invoices', 'debitNotes']);
        $vendor->current_balance = $vendor->current_balance;

        return response()->json([
            'success' => true,
            'data' => $vendor,
        ]);
    }

    public function update(VendorRequest $request, Vendor $vendor)
    {
        $old = $vendor->toArray();
        $vendor->update($request->validated());

        AuditLog::logAction('updated', 'Vendor', $vendor->id, $vendor->vendor_code, $old, $vendor->toArray(), 'Vendor updated');

        return response()->json([
            'success' => true,
            'message' => 'Vendor updated successfully',
            'data' => $vendor,
        ]);
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->settlements()->exists() || $vendor->invoices()->exists() || $vendor->debitNotes()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete vendor with financial transactions. You can set status to inactive.',
            ], 422);
        }

        $old = $vendor->toArray();
        $vendor->delete();

        AuditLog::logAction('deleted', 'Vendor', $vendor->id, $vendor->vendor_code, $old, null, 'Vendor deleted');

        return response()->json([
            'success' => true,
            'message' => 'Vendor deleted successfully',
        ]);
    }
}
