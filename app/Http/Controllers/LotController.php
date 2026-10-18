<?php

namespace App\Http\Controllers;

use App\Models\Lot;
use App\Services\InventoryLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class LotController extends Controller
{
    public function __construct(private InventoryLedgerService $ledger) {}

    public function index(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $q = Lot::with(['item:id,code,name,material_type', 'item.unit:id,code,name', 'unit:id,code,name', 'grade:id,code,name', 'branch:id,branch_name,branch_code', 'company:id,company_name,short_name'])
            ->orderByDesc('id');

        if ($companyId) $q->where('company_id', $companyId);
        else $this->scopeBranches($q);

        if ($request->filled('branch_id')) $q->where('branch_id', $request->branch_id);
        if ($request->filled('item_id')) $q->where('item_id', $request->item_id);
        if ($request->filled('grade_id')) $q->where('grade_id', $request->grade_id);
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('material_type')) $q->whereHas('item', fn ($qq) => $qq->where('material_type', $request->material_type));
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('lot_number', 'like', "%$s%")
                ->orWhereHas('item', fn ($i) => $i->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")));
        }
        if ($request->filled('oil_min')) $q->where('oil_pct', '>=', $request->oil_min);
        if ($request->filled('oil_max')) $q->where('oil_pct', '<=', $request->oil_max);

        $inactiveIds = $this->inactiveVendorIds();
        if (!empty($inactiveIds)) {
            $q->whereNot(function ($qq) use ($inactiveIds) {
                $qq->where('source', 'INWARD')
                   ->whereIn('source_id', \App\Models\InwardEntry::whereIn('party_id', $inactiveIds)->select('id'));
            });
        }

        return response()->json($q->paginate($request->get('per_page', 20)));
    }

    /** Stock summary (item/godown aggregated + lot detail). */
    public function summary(Request $request)
    {
        $filters = array_filter([
            'company_id' => $this->activeCompanyId() ?: $request->company_id,
            'branch_id' => $request->branch_id,
            'item_id' => $request->item_id,
            'grade_id' => $request->grade_id,
            'material_type' => $request->material_type,
            'oil_min' => $request->oil_min,
            'oil_max' => $request->oil_max,
        ], fn ($v) => $v !== null && $v !== '');

        return response()->json($this->ledger->stockSummary($filters));
    }

    public function show(Lot $lot)
    {
        $lot->load([
            'item:id,code,name,material_type',
            'item.unit:id,code,name',
            'unit:id,code,name',
            'grade:id,code,name',
            'branch:id,branch_name,branch_code',
            'company:id,company_name'
        ]);

        $movements = \App\Models\InventoryTransaction::where('status', 'POSTED')
            ->whereNot(function ($q) {
                $q->whereIn('reference_type', ['INWARD', 'INWARD_CANCEL'])
                  ->whereIn('reference_id', \App\Models\InwardEntry::onlyTrashed()->select('id'));
            })
            ->whereNot(function ($q) {
                $q->whereIn('reference_type', ['OUTWARD', 'OUTWARD_CANCEL'])
                  ->whereIn('reference_id', \App\Models\OutwardEntry::onlyTrashed()->select('id'));
            })
            ->where(function ($q) use ($lot) {
                if ($lot->company_id) {
                    $q->where('company_id', $lot->company_id);
                }
            })
            ->where('branch_id', $lot->branch_id)
            ->where('item_id', $lot->item_id)
            ->where('lot_number', $lot->lot_number)
            ->orderBy('date')->orderBy('id')->get();

        // Attach party names (vendor / party) for the movement history
        $vendorIds = $movements->pluck('vendor_id')
            ->merge($movements->where('party_type', 'vendor')->pluck('party_id'))
            ->filter()->unique();
        $vendors = \App\Models\Vendor::whereIn('id', $vendorIds)->pluck('vendor_name', 'id');
        foreach ($movements as $m) {
            $m->party_name = $vendors[$m->vendor_id]
                ?? ($m->party_type === 'vendor' ? ($vendors[$m->party_id] ?? null) : null)
                ?? $m->customer;
        }

        $lotData = $lot->toArray();
        $lotData['lot'] = $lot;
        $lotData['movements'] = $movements;

        return response()->json([
            'success' => true,
            'data' => $lotData,
            ...$lotData,
        ]);
    }

    /**
     * Move a lot to trash along with its dependent transactions.
     * Both are restorable from the Trash screen.
     * Only lots with zero available stock can be deleted.
     */
    public function destroy(Lot $lot)
    {
        $this->guardCompanyOwnership($lot);

        if ((float) $lot->available_qty > 0) {
            return response()->json([
                'success' => false,
                'message' => sprintf('Lot %s still has %s MT available stock. Dispatch / transfer the remaining quantity first — only zero-stock lots can be deleted.', $lot->lot_number, $lot->available_qty),
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($lot) {
            \App\Models\InventoryTransaction::where('lot_number', $lot->lot_number)
                ->where('branch_id', $lot->branch_id)
                ->where('item_id', $lot->item_id)
                ->delete();
            // Zero-out balances so the lot carries no residual stock value into trash
            $lot->update([
                'available_qty' => 0,
                'stock_value' => 0,
                'unit_cost' => 0,
                'reserved_qty' => 0,
                'status' => 'CLOSED',
            ]);
            $lot->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Lot moved to trash with its transaction history.',
        ]);
    }

    /**
     * Full lot statement PDF: opening balance + every movement (entry particular)
     * within a selected duration, with running balance and closing stock.
     */
    public function movementsPdf(Lot $lot, Request $request)
    {
        $this->guardCompanyOwnership($lot);

        $dateFrom = $request->date_from ?: null;
        $dateTo = $request->date_to ?: null;

        $lot->load([
            'item:id,code,name,material_type',
            'item.unit:id,code,name',
            'unit:id,code,name',
            'grade:id,code,name',
            'branch:id,branch_name,branch_code',
            'company:id,company_name',
        ]);

        $baseQuery = fn () => \App\Models\InventoryTransaction::where('status', 'POSTED')
            ->whereNot(function ($q) {
                $q->whereIn('reference_type', ['INWARD', 'INWARD_CANCEL'])
                  ->whereIn('reference_id', \App\Models\InwardEntry::onlyTrashed()->select('id'));
            })
            ->whereNot(function ($q) {
                $q->whereIn('reference_type', ['OUTWARD', 'OUTWARD_CANCEL'])
                  ->whereIn('reference_id', \App\Models\OutwardEntry::onlyTrashed()->select('id'));
            })
            ->where('branch_id', $lot->branch_id)
            ->where('item_id', $lot->item_id)
            ->where('lot_number', $lot->lot_number)
            ->orderBy('date')->orderBy('id');

        // Opening balance = everything strictly before the duration start
        $opening = 0.0;
        if ($dateFrom) {
            $opening = (float) (clone $baseQuery())->whereDate('date', '<', $dateFrom)->sum('signed_quantity');
        }

        $movements = (clone $baseQuery())
            ->when($dateFrom, fn ($q) => $q->whereDate('date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('date', '<=', $dateTo))
            ->get();

        // Attach party names (vendor / party)
        $vendorIds = $movements->pluck('vendor_id')
            ->merge($movements->where('party_type', 'vendor')->pluck('party_id'))
            ->filter()->unique();
        $vendors = \App\Models\Vendor::whereIn('id', $vendorIds)->pluck('vendor_name', 'id');

        $balance = $opening;
        $rows = $movements->map(function ($m) use (&$balance, $vendors) {
            $signed = (float) $m->signed_quantity;
            $balance += $signed;
            $partyName = $vendors[$m->vendor_id]
                ?? ($m->party_type === 'vendor' ? ($vendors[$m->party_id] ?? null) : null)
                ?? $m->customer;

            return [
                'date' => $m->date,
                'type' => $m->type,
                'direction' => $signed >= 0 ? 'IN' : 'OUT',
                'quantity' => (float) $m->quantity,
                'in_qty' => $signed >= 0 ? $signed : 0,
                'out_qty' => $signed < 0 ? abs($signed) : 0,
                'balance' => round($balance, 3),
                'rate' => $m->rate,
                'amount' => $m->amount,
                'party_name' => $partyName,
                'reference_type' => $m->reference_type,
                'source_reference' => $m->source_reference,
                'remarks' => $m->remarks,
            ];
        })->values();

        $totIn = (float) $rows->sum('in_qty');
        $totOut = (float) $rows->sum('out_qty');
        $closing = round($opening + $totIn - $totOut, 3);

        $company = app(\App\Services\PdfService::class)->resolveCompany($lot->branch_id);

        $pdf = Pdf::loadView('pdf.lot_movements', [
            'lot' => $lot,
            'company' => $company,
            'rows' => $rows,
            'opening' => round($opening, 3),
            'totIn' => $totIn,
            'totOut' => $totOut,
            'closing' => $closing,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ])->setPaper('a4', 'landscape');

        $filename = 'Lot_Statement_'.$lot->lot_number;
        if ($dateFrom && $dateTo) {
            $filename .= '_'.$dateFrom.'_to_'.$dateTo;
        } elseif ($dateFrom) {
            $filename .= '_from_'.$dateFrom;
        } elseif ($dateTo) {
            $filename .= '_upto_'.$dateTo;
        } else {
            $filename .= '_'.date('Ymd_His');
        }
        $filename .= '.pdf';

        return $pdf->stream($filename);
    }

    /** Lot-wise stock PDF export (optionally scoped to a duration via date_from / date_to). */
    public function pdf(Request $request)
    {
        $dateFrom = $request->date_from ?: null;
        $dateTo = $request->date_to ?: null;

        $q = Lot::with(['item:id,code,name,material_type', 'branch:id,branch_name,branch_code', 'unit:id,code,name'])
            ->where('status', 'OPEN')
            ->orderBy('lot_number');

        $companyId = $this->activeCompanyId();
        if ($companyId) $q->where('company_id', $companyId);
        else $this->scopeBranches($q);

        if ($request->filled('branch_id')) $q->where('branch_id', $request->branch_id);
        if ($request->filled('item_id')) $q->where('item_id', $request->item_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('lot_number', 'like', "%$s%")
                ->orWhereHas('item', fn ($i) => $i->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")));
        }

        $lots = $q->get();

        // When a duration is set, restrict to lots that moved within it and show period In/Out.
        $periodIn = [];
        $periodOut = [];
        if (($dateFrom || $dateTo) && $lots->isNotEmpty()) {
            $txs = \App\Models\InventoryTransaction::where('status', 'POSTED')
                ->whereIn('lot_number', $lots->pluck('lot_number')->unique())
                ->when($dateFrom, fn ($t) => $t->whereDate('date', '>=', $dateFrom))
                ->when($dateTo, fn ($t) => $t->whereDate('date', '<=', $dateTo))
                ->get(['lot_number', 'branch_id', 'item_id', 'signed_quantity']);

            foreach ($txs as $tx) {
                $key = $tx->lot_number.'|'.$tx->branch_id.'|'.$tx->item_id;
                $qty = (float) $tx->signed_quantity;
                if ($qty >= 0) $periodIn[$key] = ($periodIn[$key] ?? 0) + $qty;
                else $periodOut[$key] = ($periodOut[$key] ?? 0) + abs($qty);
            }

            $lots = $lots->filter(function ($lot) use ($periodIn, $periodOut) {
                $key = $lot->lot_number.'|'.$lot->branch_id.'|'.$lot->item_id;
                return isset($periodIn[$key]) || isset($periodOut[$key]);
            })->values();
        }

        $rows = $lots->map(function ($lot) use ($periodIn, $periodOut) {
            $key = $lot->lot_number.'|'.$lot->branch_id.'|'.$lot->item_id;
            return [
                'lot_number' => $lot->lot_number,
                'item_name' => $lot->item?->name,
                'item_code' => $lot->item?->code,
                'branch_name' => $lot->branch?->branch_name,
                'oil_pct' => $lot->oil_pct,
                'received_qty' => (float) $lot->received_qty,
                'issued_qty' => (float) $lot->issued_qty,
                'available_qty' => (float) $lot->available_qty,
                'stock_value' => (float) $lot->stock_value,
                'period_in' => $periodIn[$key] ?? null,
                'period_out' => $periodOut[$key] ?? null,
            ];
        })->values();

        $totLots = $rows->count();
        $totAvailable = (float) $rows->sum('available_qty');
        $totValue = (float) $rows->sum('stock_value');
        $totIn = (float) $rows->sum(fn ($r) => $r['period_in'] ?? $r['received_qty']);
        $totOut = (float) $rows->sum(fn ($r) => $r['period_out'] ?? $r['issued_qty']);

        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        $itemId = $request->item_id ? (int) $request->item_id : null;

        $company = app(\App\Services\PdfService::class)->resolveCompany($branchId ?: $lots->first()?->branch_id);
        $branchName = $branchId ? \App\Models\Branch::find($branchId)?->branch_name : null;
        $itemName = $itemId ? \App\Models\Item::find($itemId)?->name : null;

        $pdf = Pdf::loadView('pdf.lots_stock', [
            'rows' => $rows,
            'company' => $company,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'branchName' => $branchName,
            'itemName' => $itemName,
            'search' => $request->search,
            'totLots' => $totLots,
            'totIn' => $totIn,
            'totOut' => $totOut,
            'totAvailable' => $totAvailable,
            'totValue' => $totValue,
            'hasDuration' => (bool) ($dateFrom || $dateTo),
        ])->setPaper('a4', 'landscape');

        $filename = 'Lots_Stock_Report';
        if ($dateFrom && $dateTo) {
            $filename .= '_' . $dateFrom . '_to_' . $dateTo;
        } elseif ($dateFrom) {
            $filename .= '_from_' . $dateFrom;
        } elseif ($dateTo) {
            $filename .= '_upto_' . $dateTo;
        } else {
            $filename .= '_' . date('Ymd_His');
        }
        $filename .= '.pdf';

        return $pdf->stream($filename);
    }
}
