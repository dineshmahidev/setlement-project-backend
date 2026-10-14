<?php

namespace App\Http\Controllers;

use App\Models\InwardEntry;
use App\Services\InventoryLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InwardController extends Controller
{
    public function __construct(private InventoryLedgerService $ledger) {}

    public function index(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $q = InwardEntry::with(['party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'items.item:id,code,name'])
            ->orderByDesc('id');
        if ($companyId) $q->where('company_id', $companyId);
        else $this->scopeBranches($q, 'branch_id');

        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('branch_id')) $q->where('branch_id', $request->branch_id);
        if ($request->filled('party_id')) $q->where('party_id', $request->party_id);
        if ($request->filled('from_date')) $q->whereDate('date', '>=', $request->from_date);
        if ($request->filled('to_date')) $q->whereDate('date', '<=', $request->to_date);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('entry_number', 'like', "%$s%")->orWhere('load_reference', 'like', "%$s%"));
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'party_id' => 'nullable|exists:vendors,id',
            'date' => 'required|date',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:150',
            'driver_mobile' => 'nullable|string|max:30',
            'load_reference' => 'nullable|string|max:100',
            'bill_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.lot_number' => 'nullable|string|max:100',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.grade_id' => 'nullable|exists:grades,id',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.additional_charges' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        $companyId = $this->activeCompanyId() ?? \App\Models\Branch::find($data['branch_id'])?->company_id ?? 1;

        $entry = DB::transaction(function () use ($data, $companyId) {
            $entryNumber = $this->ledger->nextNumber('INW', 'inward_entries', 'entry_number');
            $total = 0;
            $items = [];
            foreach ($data['items'] as $i) {
                $amount = round(((float) $i['quantity']) * ((float) ($i['rate'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2);
                $total += $amount;
                $items[] = $i + ['amount' => $amount];
            }

            $entry = InwardEntry::create([
                'entry_number' => $entryNumber,
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'],
                'party_id' => $data['party_id'] ?? null,
                'date' => $data['date'],
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'load_reference' => $data['bill_no'] ?? $data['load_reference'] ?? null,
                'total_amount' => round($total, 2),
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id() ?? $request->user()?->id,
            ]);

            foreach ($items as $i) {
                $entry->items()->create($i);
            }

            // Auto-confirm: immediately credit lots and update inventory ledger (no manual confirm needed)
            $userId = (int) (auth()->id() ?? $request->user()?->id ?? 1);
            return $this->ledger->confirmInward($entry, $userId);
        });

        return response()->json($entry->load(['items.item:id,code,name', 'party:id,vendor_name', 'branch:id,branch_name']), 201);
    }

    public function show(InwardEntry $inward)
    {
        $inward->load(['items.item:id,code,name', 'items.grade:id,code,name', 'party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'creator:id,name', 'confirmer:id,name']);
        return response()->json($inward);
    }

    public function update(Request $request, InwardEntry $inward)
    {
        if ($inward->status !== 'DRAFT') {
            return response()->json(['success' => false, 'message' => 'Only draft entries can be edited.'], 422);
        }
        $data = $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'party_id' => 'nullable|exists:vendors,id',
            'date' => 'sometimes|date',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:150',
            'driver_mobile' => 'nullable|string|max:30',
            'load_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);
        $inward->update($data);

        if ($request->has('items')) {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|exists:items,id',
                'items.*.quantity' => 'required|numeric|gt:0',
            ]);
            DB::transaction(function () use ($inward, $request) {
                $inward->items()->delete();
                $total = 0;
                foreach ($request->items as $i) {
                    $amount = round(((float) $i['quantity']) * ((float) ($i['rate'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2);
                    $total += $amount;
                    $inward->items()->create($i + ['amount' => $amount]);
                }
                $inward->update(['total_amount' => round($total, 2)]);
            });
        }

        return response()->json($inward->fresh(['items.item:id,code,name', 'party:id,vendor_name', 'branch:id,branch_name']));
    }

    public function confirm(Request $request, InwardEntry $inward)
    {
        try {
            $entry = $this->ledger->confirmInward($inward, $request->user()->id);
            return response()->json(['success' => true, 'data' => $entry]);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function cancel(Request $request, InwardEntry $inward)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        try {
            $entry = $this->ledger->cancelInward($inward, $request->user()->id, $request->reason);
            return response()->json(['success' => true, 'data' => $entry]);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function destroy(InwardEntry $inward)
    {
        $userId = (int) (auth()->id() ?? request()->user()?->id ?? 1);
        try {
            DB::transaction(function () use ($inward, $userId) {
                if ($inward->status === 'CONFIRMED') {
                    $this->ledger->cancelInward($inward, $userId, 'Moved to trash');
                }
                $inward->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Inward entry deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function pdf(InwardEntry $inward)
    {
        $pdf = app(\App\Services\PdfService::class)->generateInwardPdf($inward);
        $fileName = str_replace(['/', '\\'], '-', $inward->entry_number) . '.pdf';
        return $pdf->stream($fileName);
    }
}
