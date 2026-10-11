<?php

namespace App\Http\Controllers;

use App\Models\OutwardEntry;
use App\Services\InventoryLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutwardController extends Controller
{
    public function __construct(private InventoryLedgerService $ledger) {}

    public function index(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $q = OutwardEntry::with(['party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'items.item:id,code,name'])
            ->orderByDesc('id');
        if ($companyId) $q->where('company_id', $companyId);
        else $this->scopeBranches($q, 'branch_id');

        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('dispatch_type')) $q->where('dispatch_type', $request->dispatch_type);
        if ($request->filled('branch_id')) $q->where('branch_id', $request->branch_id);
        if ($request->filled('party_id')) $q->where('party_id', $request->party_id);
        if ($request->filled('from_date')) $q->whereDate('date', '>=', $request->from_date);
        if ($request->filled('to_date')) $q->whereDate('date', '<=', $request->to_date);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('entry_number', 'like', "%$s%")->orWhere('vehicle_number', 'like', "%$s%"));
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'party_id' => 'nullable|exists:vendors,id',
            'date' => 'required|date',
            'dispatch_type' => 'required|in:DIRECT,MIXED',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:150',
            'driver_mobile' => 'nullable|string|max:30',
            'destination' => 'nullable|string|max:200',
            'is_sale' => 'nullable|boolean',
            'payment_status' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.lot_id' => 'nullable|exists:lots,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.grade_id' => 'nullable|exists:grades,id',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.additional_charges' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        $entry = DB::transaction(function () use ($data, $companyId) {
            $entryNumber = $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number');
            $total = 0;
            $items = [];
            foreach ($data['items'] as $i) {
                $amount = round(((float) $i['quantity']) * ((float) ($i['rate'] ?? 0)) - ((float) ($i['discount'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2);
                $total += $amount;
                $items[] = $i + ['amount' => $amount];
            }

            $entry = OutwardEntry::create([
                'entry_number' => $entryNumber,
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'],
                'party_id' => $data['party_id'] ?? null,
                'date' => $data['date'],
                'dispatch_type' => $data['dispatch_type'],
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'destination' => $data['destination'] ?? null,
                'total_amount' => round($total, 2),
                'is_sale' => $data['is_sale'] ?? false,
                'payment_status' => $data['payment_status'] ?? null,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'created_by' => request()->user()->id,
            ]);

            foreach ($items as $i) {
                $entry->items()->create($i);
            }

            return $entry;
        });

        return response()->json($entry->load(['items.item:id,code,name', 'party:id,vendor_name', 'branch:id,branch_name']), 201);
    }

    public function show(OutwardEntry $outward)
    {
        $outward->load(['items.item:id,code,name', 'items.lot:id,lot_number', 'items.grade:id,code,name', 'party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'creator:id,name', 'confirmer:id,name']);
        return response()->json($outward);
    }

    public function update(Request $request, OutwardEntry $outward)
    {
        if ($outward->status !== 'DRAFT') {
            return response()->json(['success' => false, 'message' => 'Only draft entries can be edited.'], 422);
        }
        $data = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'party_id' => 'nullable|exists:vendors,id',
            'date' => 'sometimes|date',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:150',
            'driver_mobile' => 'nullable|string|max:30',
            'destination' => 'nullable|string|max:200',
            'notes' => 'nullable|string',
        ]);
        $outward->update($data);

        if ($request->has('items')) {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|exists:items,id',
                'items.*.quantity' => 'required|numeric|gt:0',
            ]);
            DB::transaction(function () use ($outward, $request) {
                $outward->items()->delete();
                $total = 0;
                foreach ($request->items as $i) {
                    $amount = round(((float) $i['quantity']) * ((float) ($i['rate'] ?? 0)) - ((float) ($i['discount'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2);
                    $total += $amount;
                    $outward->items()->create($i + ['amount' => $amount]);
                }
                $outward->update(['total_amount' => round($total, 2)]);
            });
        }

        return response()->json($outward->fresh(['items.item:id,code,name', 'party:id,vendor_name', 'branch:id,branch_name']));
    }

    public function confirm(Request $request, OutwardEntry $outward)
    {
        try {
            $entry = $this->ledger->confirmOutward($outward, $request->user()->id);
            return response()->json(['success' => true, 'data' => $entry]);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function cancel(Request $request, OutwardEntry $outward)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        try {
            $entry = $this->ledger->cancelOutward($outward, $request->user()->id, $request->reason);
            return response()->json(['success' => true, 'data' => $entry]);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }
}
