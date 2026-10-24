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
        $q = OutwardEntry::with(['party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'items.item:id,code,name', 'items.unit:id,code,name', 'items.lot:id,lot_number'])
            ->orderByDesc('id');
        if ($companyId) $q->where('company_id', $companyId);
        else $this->scopeBranches($q, 'branch_id');

        if (! $request->filled('party_id')) {
            $q->where(function ($query) {
                $query->whereNull('party_id')
                      ->orWhereHas('party', fn ($pq) => $pq->where('status', 'active'));
            });
        }

        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('dispatch_type')) $q->where('dispatch_type', $request->dispatch_type);
        if ($request->filled('branch_id')) $q->where('branch_id', $request->branch_id);
        if ($request->filled('party_id')) $q->where('party_id', $request->party_id);
        if ($request->filled('from_date')) $q->whereDate('date', '>=', $request->from_date);
        if ($request->filled('to_date')) $q->whereDate('date', '<=', $request->to_date);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('entry_number', 'like', "%$s%")
                ->orWhere('bill_no', 'like', "%$s%")
                ->orWhere('vehicle_number', 'like', "%$s%"));
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bill_no' => 'nullable|string|max:100',
            'branch_id' => 'required|exists:branches,id',
            'party_id' => 'nullable|exists:vendors,id',
            'date' => 'required|date',
            'dispatch_type' => 'nullable|in:DIRECT,MIXED',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:150',
            'driver_mobile' => 'nullable|string|max:30',
            'destination' => 'nullable|string|max:200',
            'is_sale' => 'nullable|boolean',
            'payment_status' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.lot_id' => 'nullable|exists:lots,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.grade_id' => 'nullable',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.additional_charges' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        $companyId = $this->activeCompanyId() ?? \App\Models\Branch::find($data['branch_id'])?->company_id ?? 1;
        $userId = (int) (auth()->id() ?? $request->user()?->id ?? 1);

        $entry = DB::transaction(function () use ($data, $companyId, $userId, $request) {
            $entryNumber = $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number');
            $total = 0;
            $items = [];
            foreach ($data['items'] as $i) {
                // If unit_id is missing, auto-pick from lot (fixed in inward) or item master
                if (empty($i['unit_id'])) {
                    if (!empty($i['lot_id'])) {
                        $i['unit_id'] = \App\Models\Lot::whereKey($i['lot_id'])->value('unit_id');
                    }
                    if (empty($i['unit_id']) && !empty($i['item_id'])) {
                        $i['unit_id'] = \App\Models\Item::whereKey($i['item_id'])->value('unit_id');
                    }
                }
                // If rate is missing, auto-pick from lot unit_cost
                if ((!isset($i['rate']) || $i['rate'] === '' || (float)$i['rate'] == 0) && !empty($i['lot_id'])) {
                    $lotCost = \App\Models\Lot::whereKey($i['lot_id'])->value('unit_cost');
                    if ($lotCost) {
                        $i['rate'] = (float) $lotCost;
                    }
                }
                // If oil_pct is missing, auto-pick from lot (from inward)
                if ((!isset($i['oil_pct']) || $i['oil_pct'] === '' || $i['oil_pct'] === null) && !empty($i['lot_id'])) {
                    $lotOil = \App\Models\Lot::whereKey($i['lot_id'])->value('oil_pct');
                    if ($lotOil !== null) {
                        $i['oil_pct'] = (float) $lotOil;
                    }
                }
                $amount = round(((float) $i['quantity']) * ((float) ($i['rate'] ?? 0)) - ((float) ($i['discount'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2);
                $total += $amount;
                $items[] = $i + ['amount' => $amount];
            }

            $entry = OutwardEntry::create([
                'entry_number' => $entryNumber,
                'bill_no' => $data['bill_no'] ?? null,
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'],
                'party_id' => $data['party_id'] ?? null,
                'date' => $data['date'],
                'dispatch_type' => $data['dispatch_type'] ?? 'DIRECT',
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'destination' => $data['destination'] ?? null,
                'total_amount' => round($total, 2),
                'is_sale' => $data['is_sale'] ?? false,
                'payment_status' => $data['payment_status'] ?? null,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? $data['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($items as $i) {
                $entry->items()->create($i);
            }

            return $entry;
        });

        // Automatically confirm outward to deduct stock from lots
        try {
            $entry = $this->ledger->confirmOutward($entry, $userId);
        } catch (\Throwable $e) {
            // keep entry if confirmation encounters an issue or re-throw
            throw $e;
        }

        return response()->json($entry->load(['items.item:id,code,name', 'items.unit:id,code,name', 'items.lot:id,lot_number', 'party:id,vendor_name', 'branch:id,branch_name']), 201);
    }

    public function show(OutwardEntry $outward)
    {
        $outward->load(['items.item:id,code,name', 'items.unit:id,code,name', 'items.lot:id,lot_number', 'party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'creator:id,name', 'confirmer:id,name']);
        return response()->json($outward);
    }

    public function update(Request $request, OutwardEntry $outward)
    {
        $userId = (int) (auth()->id() ?? $request->user()?->id ?? 1);

        $data = $request->validate([
            'bill_no' => 'nullable|string|max:100',
            'branch_id' => 'sometimes|exists:branches,id',
            'party_id' => 'nullable|exists:vendors,id',
            'date' => 'sometimes|date',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:150',
            'driver_mobile' => 'nullable|string|max:30',
            'destination' => 'nullable|string|max:200',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.item_id' => 'required_with:items|exists:items,id',
            'items.*.lot_id' => 'nullable|exists:lots,id',
            'items.*.quantity' => 'required_with:items|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.rate' => 'nullable|numeric',
            'items.*.oil_pct' => 'nullable|numeric',
            'items.*.grade_id' => 'nullable',
            'items.*.discount' => 'nullable|numeric',
            'items.*.additional_charges' => 'nullable|numeric',
        ]);

        if (isset($data['remarks']) && empty($data['notes'])) {
            $data['notes'] = $data['remarks'];
        }

        try {
            DB::transaction(function () use ($outward, $data, $request, $userId) {
                $headerData = collect($data)->except(['items', 'remarks'])->all();

                if ($outward->status === 'CONFIRMED') {
                    // In-place edit: ledger rows are updated (not duplicated), lots
                    // adjusted by delta, entry stays CONFIRMED.
                    $outward->update($headerData);
                    if ($request->has('items') && is_array($request->items)) {
                        $lines = [];
                        foreach ($request->items as $i) {
                            $qty = (float) $i['quantity'];
                            $rate = (float) ($i['rate'] ?? 0);
                            $lines[] = [
                                'item_id' => (int) $i['item_id'],
                                'lot_id' => ! empty($i['lot_id']) ? (int) $i['lot_id'] : null,
                                'quantity' => $qty,
                                'unit_id' => $i['unit_id'] ?? null,
                                'oil_pct' => $i['oil_pct'] ?? null,
                                'grade_id' => $i['grade_id'] ?? null,
                                'rate' => $i['rate'] ?? null,
                                'amount' => round($qty * $rate - ((float) ($i['discount'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2),
                            ];
                        }
                        $this->ledger->updatePostedOutward($outward, $lines, $userId);
                        $outward->update(['total_amount' => round((float) collect($lines)->sum('amount'), 2)]);
                    }
                    return;
                }

                $headerData['status'] = 'DRAFT';
                $outward->update($headerData);

                if ($request->has('items') && is_array($request->items)) {
                    $outward->items()->delete();
                    $total = 0;
                    foreach ($request->items as $i) {
                        $rate = (float) ($i['rate'] ?? 0);
                        $qty = (float) $i['quantity'];
                        $amount = round($qty * $rate - ((float) ($i['discount'] ?? 0)) + ((float) ($i['additional_charges'] ?? 0)), 2);
                        $total += $amount;
                        $outward->items()->create([
                            'item_id' => $i['item_id'],
                            'lot_id' => $i['lot_id'] ?? null,
                            'quantity' => $qty,
                            'unit_id' => $i['unit_id'] ?? null,
                            'oil_pct' => $i['oil_pct'] ?? null,
                            'grade_id' => $i['grade_id'] ?? null,
                            'rate' => $rate,
                            'amount' => $amount,
                        ]);
                    }
                    $outward->update(['total_amount' => round($total, 2)]);
                }

                $this->ledger->confirmOutward($outward, $userId);
            });

            return response()->json([
                'success' => true,
                'message' => 'Dispatch entry updated successfully!',
                'data' => $outward->fresh(['items.item:id,code,name', 'items.unit:id,code,name', 'items.lot:id,lot_number', 'party:id,vendor_name', 'branch:id,branch_name']),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
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

    public function destroy(OutwardEntry $outward)
    {
        try {
            $this->ledger->trashOutward($outward);

            return response()->json([
                'success' => true,
                'message' => 'Outward entry moved to trash',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function pdf(OutwardEntry $outward)
    {
        $pdf = app(\App\Services\PdfService::class)->generateOutwardPdf($outward);
        $fileName = str_replace(['/', '\\'], '-', $outward->entry_number) . '.pdf';
        return $pdf->stream($fileName);
    }

    public function reportPdf(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $q = OutwardEntry::with(['party:id,vendor_name,vendor_code', 'branch:id,branch_name,branch_code', 'items.item:id,code,name', 'items.unit:id,code,name', 'items.lot:id,lot_number'])
            ->orderBy('date', 'desc')
            ->orderByDesc('id');
        if ($companyId) $q->where('company_id', $companyId);
        else $this->scopeBranches($q, 'branch_id');

        if ($request->filled('branch_id')) $q->where('branch_id', $request->branch_id);
        if ($request->filled('party_id')) $q->where('party_id', $request->party_id);
        if ($request->filled('from_date')) $q->whereDate('date', '>=', $request->from_date);
        if ($request->filled('to_date')) $q->whereDate('date', '<=', $request->to_date);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('entry_number', 'like', "%$s%")
                ->orWhere('bill_no', 'like', "%$s%")
                ->orWhere('vehicle_number', 'like', "%$s%"));
        }

        $entries = $q->get();
        $company = app(\App\Services\PdfService::class)->resolveCompany($request->branch_id ?? null);
        $branchName = $request->filled('branch_id') ? \App\Models\Branch::find($request->branch_id)?->branch_name : null;
        $partyName = $request->filled('party_id') ? \App\Models\Vendor::find($request->party_id)?->vendor_name : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.outward_report', [
            'entries' => $entries,
            'company' => $company,
            'fromDate' => $request->from_date,
            'toDate' => $request->to_date,
            'branchName' => $branchName,
            'partyName' => $partyName,
        ])->setPaper('a4', 'landscape');

        $fileName = 'Outward_Report';
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $fileName .= '_' . $request->from_date . '_to_' . $request->to_date;
        }
        return $pdf->stream($fileName . '.pdf');
    }
}

