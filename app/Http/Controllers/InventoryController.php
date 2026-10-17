<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\StockTransfer;
use App\Services\InventoryLedgerService;
use App\Services\InventoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $service, private InventoryLedgerService $ledger) {}

    public function index(Request $request)
    {
        $query = InventoryTransaction::with(['item.unit', 'branch', 'vendor', 'vehicle'])
            ->orderByDesc('date')->orderByDesc('id');

        $this->scopeBranches($query);
        $this->scopeActiveVendorTransactions($query);

        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('item_id')) $query->where('item_id', $request->item_id);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('transaction_number', 'like', "%$s%")
                ->orWhere('lot_number', 'like', "%$s%"));
        }
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('date', '<=', $request->date_to);

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    /** Real inventory KPIs for the dashboard (company-scoped). */
    public function dashboardKpis(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        if (! $companyId) {
            return response()->json(['message' => 'No active company.'], 422);
        }
        $data = $this->ledger->dashboardKpis($companyId, $branchId, $request->from_date, $request->to_date);
        return response()->json($data);
    }

    public function stock(Request $request)
    {
        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        $dateFrom = $request->date_from ?: null;
        $dateTo = $request->date_to ?: null;
        $hideZero = $request->boolean('hide_zero');

        // Strictly scope to the active company's items matching ItemController::index()
        $itemQuery = $this->scopeCompany(Item::query())
            ->with(['unit', 'category'])
            ->orderBy('name');

        if ($request->filled('item_id')) {
            $itemQuery->where('id', $request->item_id);
        }

        $items = $itemQuery->get();
        $allowedBranchIds = $branchId ? [$branchId] : $this->allowedBranchIds();

        $rows = [];
        foreach ($items as $item) {
            $tx = InventoryTransaction::where('item_id', $item->id)
                ->where('status', 'POSTED')
                ->where(fn($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['SETTLEMENT_BILL', 'Settlement Bill', 'SETTLEMENT']));
            $this->scopeActiveVendorTransactions($tx);
            if ($branchId) {
                $tx->where('branch_id', $branchId);
            } elseif ($allowedBranchIds) {
                $tx->whereIn('branch_id', $allowedBranchIds);
            }

            $inQ = clone $tx;
            $outQ = clone $tx;

            if ($dateFrom) {
                $inQ->whereDate('date', '>=', $dateFrom);
                $outQ->whereDate('date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $inQ->whereDate('date', '<=', $dateTo);
                $outQ->whereDate('date', '<=', $dateTo);
            }

            $in = (clone $inQ)->where('type', 'STOCK_IN')->sum('quantity')
                + (clone $inQ)->where('type', 'TRANSFER_IN')->sum('quantity')
                + (clone $inQ)->where('type', 'ADJUSTMENT_IN')->sum('quantity');

            $out = (clone $outQ)->where('type', 'STOCK_OUT')->sum('quantity')
                + (clone $outQ)->where('type', 'TRANSFER_OUT')->sum('quantity')
                + (clone $outQ)->where('type', 'ADJUSTMENT_OUT')->sum('quantity');

            // Cumulative available stock as of dateTo (or all time if no dateTo)
            $allTx = InventoryTransaction::where('item_id', $item->id)
                ->where('status', 'POSTED')
                ->where(fn($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['SETTLEMENT_BILL', 'Settlement Bill', 'SETTLEMENT']));
            $this->scopeActiveVendorTransactions($allTx);
            if ($branchId) {
                $allTx->where('branch_id', $branchId);
            } elseif ($allowedBranchIds) {
                $allTx->whereIn('branch_id', $allowedBranchIds);
            }
            if ($dateTo) {
                $allTx->whereDate('date', '<=', $dateTo);
            }

            $cumIn = (clone $allTx)->where('type', 'STOCK_IN')->sum('quantity')
                + (clone $allTx)->where('type', 'TRANSFER_IN')->sum('quantity')
                + (clone $allTx)->where('type', 'ADJUSTMENT_IN')->sum('quantity');

            $cumOut = (clone $allTx)->where('type', 'STOCK_OUT')->sum('quantity')
                + (clone $allTx)->where('type', 'TRANSFER_OUT')->sum('quantity')
                + (clone $allTx)->where('type', 'ADJUSTMENT_OUT')->sum('quantity');

            $available = round((float) $cumIn - (float) $cumOut, 3);
            $totIn = round((float) $in, 3);
            $totOut = round((float) $out, 3);
            $opening = round((float) $cumIn - (float) $cumOut - ((float) $in - (float) $out), 3);
            $min = (float) $item->min_stock_level;

            $status = 'Normal';
            if ($available <= 0) {
                $status = 'Out of Stock';
            } elseif ($min > 0 && $available <= $min) {
                $status = 'Low Stock';
            }

            if ($hideZero && abs($available) < 0.0001) {
                continue;
            }

            $rows[] = [
                'item_id' => $item->id,
                'item_code' => $item->code,
                'item_name' => $item->name,
                'unit' => $item->unit?->code,
                'category' => $item->category?->name,
                'opening' => $opening,
                'total_in' => $totIn,
                'total_out' => $totOut,
                'available' => $available,
                'min_stock_level' => $min,
                'max_stock_level' => (float) $item->max_stock_level,
                'status' => $status,
            ];
        }

        return response()->json($rows);
    }

    public function stockPdf(Request $request)
    {
        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        $dateFrom = $request->date_from ?: null;
        $dateTo = $request->date_to ?: null;
        $itemId = $request->item_id ? (int) $request->item_id : null;
        $search = $request->search ? trim($request->search) : null;

        $stockResponse = $this->stock($request);
        $rows = json_decode($stockResponse->getContent(), true) ?: [];

        if ($itemId) {
            $rows = array_values(array_filter($rows, fn ($r) => (int)($r['item_id'] ?? 0) === $itemId));
        }
        if ($search) {
            $s = strtolower($search);
            $rows = array_values(array_filter($rows, fn ($r) => 
                str_contains(strtolower($r['item_name'] ?? ''), $s) ||
                str_contains(strtolower($r['item_code'] ?? ''), $s)
            ));
        }

        $totIn = array_sum(array_column($rows, 'total_in'));
        $totOut = array_sum(array_column($rows, 'total_out'));
        $totAvailable = array_sum(array_column($rows, 'available'));

        $company = app(\App\Services\PdfService::class)->resolveCompany($branchId);
        $branchName = $branchId ? \App\Models\Branch::find($branchId)?->branch_name : null;
        $itemName = $itemId ? \App\Models\Item::find($itemId)?->name : null;

        $pdf = Pdf::loadView('pdf.current_stock', [
            'rows' => $rows,
            'company' => $company,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'branchName' => $branchName,
            'itemName' => $itemName,
            'totIn' => $totIn,
            'totOut' => $totOut,
            'totAvailable' => $totAvailable,
        ])->setPaper('a4', 'landscape');

        $filename = 'Current_Stock_Report';
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

    public function ledger(Request $request)
    {
        $query = InventoryTransaction::with(['item.unit', 'branch', 'vendor', 'vehicle'])
            ->orderBy('date')->orderBy('id')
            ->where('status', 'POSTED')
            ->where(fn($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['SETTLEMENT_BILL', 'Settlement Bill', 'SETTLEMENT']));

        $this->scopeBranches($query);
        $this->scopeActiveVendorTransactions($query);

        if ($request->filled('item_id')) $query->where('item_id', $request->item_id);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('date', '<=', $request->date_to);

        $txs = $query->get();

        $running = [];
        if ($request->filled('date_from')) {
            $priorQuery = InventoryTransaction::where('status', 'POSTED')
                ->where(fn($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['SETTLEMENT_BILL', 'Settlement Bill', 'SETTLEMENT']))
                ->whereDate('date', '<', $request->date_from);
            $this->scopeBranches($priorQuery);
            $this->scopeActiveVendorTransactions($priorQuery);
            if ($request->filled('item_id')) $priorQuery->where('item_id', $request->item_id);
            if ($request->filled('branch_id')) $priorQuery->where('branch_id', $request->branch_id);
            $priorBalances = $priorQuery->selectRaw("CONCAT(branch_id, '_', item_id) as k, SUM(signed_quantity) as total_qty")
                ->groupBy('branch_id', 'item_id')
                ->pluck('total_qty', 'k');
            foreach ($priorBalances as $k => $qty) {
                $running[$k] = (float) $qty;
            }
        }

        $rows = $txs->map(function ($tx) use (&$running) {
            $key = $tx->branch_id.'_'.$tx->item_id;
            $opening = $running[$key] ?? 0;
            $closing = $opening + (float) $tx->signed_quantity;
            $running[$key] = $closing;

            $map = [
                'STOCK_IN' => ['in' => $tx->quantity, 'out' => 0],
                'TRANSFER_IN' => ['tin' => $tx->quantity, 'out' => 0],
                'STOCK_OUT' => ['out' => $tx->quantity],
                'TRANSFER_OUT' => ['tout' => $tx->quantity],
                'ADJUSTMENT_IN' => ['adj' => $tx->quantity],
                'ADJUSTMENT_OUT' => ['adj' => -$tx->quantity],
                'OPENING' => ['in' => $tx->quantity],
            ];
            $cols = $map[$tx->type] ?? [];

            $partyName = $tx->vendor?->vendor_name ?: ($tx->vendor?->name ?: ($tx->customer ?: $tx->destination));
            if (!$partyName && $tx->party_id) {
                $partyName = \App\Models\Vendor::whereKey($tx->party_id)->value('vendor_name');
            }
            if (!$partyName && in_array($tx->reference_type, ['OUTWARD', 'OUTWARD_CANCEL']) && $tx->reference_id) {
                $partyName = \App\Models\OutwardEntry::whereKey($tx->reference_id)->with('party')->first()?->party?->vendor_name;
            }
            if (!$partyName && in_array($tx->reference_type, ['INWARD', 'INWARD_CANCEL']) && $tx->reference_id) {
                $partyName = \App\Models\InwardEntry::whereKey($tx->reference_id)->with('party')->first()?->party?->vendor_name;
            }

            $vehicleNumber = $tx->vehicle?->number ?: $tx->vehicle?->vehicle_number;
            if (!$vehicleNumber && in_array($tx->reference_type, ['OUTWARD', 'OUTWARD_CANCEL']) && $tx->reference_id) {
                $vehicleNumber = \App\Models\OutwardEntry::whereKey($tx->reference_id)->value('vehicle_number');
            }
            if (!$vehicleNumber && in_array($tx->reference_type, ['INWARD', 'INWARD_CANCEL']) && $tx->reference_id) {
                $vehicleNumber = \App\Models\InwardEntry::whereKey($tx->reference_id)->value('vehicle_number');
            }

            $inQty = round((float) ($cols['in'] ?? $cols['tin'] ?? 0), 3);
            $outQty = round((float) ($cols['out'] ?? $cols['tout'] ?? 0), 3);
            if ($tx->type === 'ADJUSTMENT_IN') $inQty = round((float) $tx->quantity, 3);
            if ($tx->type === 'ADJUSTMENT_OUT') $outQty = round((float) $tx->quantity, 3);

            $displayType = (in_array($tx->type, ['STOCK_IN', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'OPENING', 'PRODUCTION_OUT']) || $inQty > 0)
                ? 'STOCK_IN'
                : 'STOCK_OUT';

            return [
                'date' => $tx->date->toDateString(),
                'lot' => $tx->lot_number ?: '—',
                'party' => $partyName ?: '—',
                'vendor_customer' => $partyName ?: '—',
                'type' => $displayType,
                'in' => $inQty,
                'out' => $outQty,
                'stock_in' => $inQty,
                'stock_out' => $outQty,
                'vehicle' => $vehicleNumber ?: '—',
                'transaction_number' => $tx->transaction_number,
                'branch' => $tx->branch?->name,
                'branch_id' => $tx->branch_id,
                'item' => $tx->item?->name,
                'item_id' => $tx->item_id,
                'unit' => $tx->item?->unit?->code,
                'opening_balance' => round($opening, 3),
                'closing_balance' => round($closing, 3),
            ];
        });

        return response()->json([
            'data' => $rows->values(),
            'links' => null,
            'meta' => ['total' => $rows->count()],
        ]);
    }

    public function ledgerPdf(Request $request)
    {
        $query = InventoryTransaction::with(['item.unit', 'branch', 'vendor', 'vehicle'])
            ->orderBy('date')->orderBy('id')
            ->where('status', 'POSTED');

        $this->scopeBranches($query);
        $this->scopeActiveVendorTransactions($query);

        if ($request->filled('item_id')) $query->where('item_id', $request->item_id);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('date_from')) $query->whereDate('date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('date', '<=', $request->date_to);

        $txs = $query->get();

        $running = [];
        if ($request->filled('date_from')) {
            $priorQuery = InventoryTransaction::where('status', 'POSTED')
                ->whereDate('date', '<', $request->date_from);
            $this->scopeBranches($priorQuery);
            $this->scopeActiveVendorTransactions($priorQuery);
            if ($request->filled('item_id')) $priorQuery->where('item_id', $request->item_id);
            if ($request->filled('branch_id')) $priorQuery->where('branch_id', $request->branch_id);
            $priorBalances = $priorQuery->selectRaw("CONCAT(branch_id, '_', item_id) as k, SUM(signed_quantity) as total_qty")
                ->groupBy('branch_id', 'item_id')
                ->pluck('total_qty', 'k');
            foreach ($priorBalances as $k => $qty) {
                $running[$k] = (float) $qty;
            }
        }

        $totIn = 0;
        $totOut = 0;
        $latestClosingByKey = [];

        $rows = $txs->map(function ($tx) use (&$running, &$totIn, &$totOut, &$latestClosingByKey) {
            $key = $tx->branch_id.'_'.$tx->item_id;
            $opening = $running[$key] ?? 0;
            $closing = $opening + (float) $tx->signed_quantity;
            $running[$key] = $closing;

            $map = [
                'STOCK_IN' => ['in' => $tx->quantity, 'out' => 0],
                'TRANSFER_IN' => ['tin' => $tx->quantity, 'out' => 0],
                'STOCK_OUT' => ['out' => $tx->quantity],
                'TRANSFER_OUT' => ['tout' => $tx->quantity],
                'ADJUSTMENT_IN' => ['adj' => $tx->quantity],
                'ADJUSTMENT_OUT' => ['adj' => -$tx->quantity],
                'OPENING' => ['in' => $tx->quantity],
            ];
            $cols = $map[$tx->type] ?? [];

            $partyName = $tx->vendor?->vendor_name ?: ($tx->vendor?->name ?: ($tx->customer ?: $tx->destination));
            if (!$partyName && $tx->party_id) {
                $partyName = \App\Models\Vendor::whereKey($tx->party_id)->value('vendor_name');
            }
            if (!$partyName && in_array($tx->reference_type, ['OUTWARD', 'OUTWARD_CANCEL']) && $tx->reference_id) {
                $partyName = \App\Models\OutwardEntry::whereKey($tx->reference_id)->with('party')->first()?->party?->vendor_name;
            }
            if (!$partyName && in_array($tx->reference_type, ['INWARD', 'INWARD_CANCEL']) && $tx->reference_id) {
                $partyName = \App\Models\InwardEntry::whereKey($tx->reference_id)->with('party')->first()?->party?->vendor_name;
            }

            $vehicleNumber = $tx->vehicle?->number ?: $tx->vehicle?->vehicle_number;
            if (!$vehicleNumber && in_array($tx->reference_type, ['OUTWARD', 'OUTWARD_CANCEL']) && $tx->reference_id) {
                $vehicleNumber = \App\Models\OutwardEntry::whereKey($tx->reference_id)->value('vehicle_number');
            }
            if (!$vehicleNumber && in_array($tx->reference_type, ['INWARD', 'INWARD_CANCEL']) && $tx->reference_id) {
                $vehicleNumber = \App\Models\InwardEntry::whereKey($tx->reference_id)->value('vehicle_number');
            }

            $inQty = round((float) ($cols['in'] ?? $cols['tin'] ?? 0), 3);
            $outQty = round((float) ($cols['out'] ?? $cols['tout'] ?? 0), 3);
            if ($tx->type === 'ADJUSTMENT_IN') $inQty = round((float) $tx->quantity, 3);
            if ($tx->type === 'ADJUSTMENT_OUT') $outQty = round((float) $tx->quantity, 3);

            $totIn += $inQty;
            $totOut += $outQty;
            $latestClosingByKey[$key] = round($closing, 3);

            $displayType = (in_array($tx->type, ['STOCK_IN', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'OPENING', 'PRODUCTION_OUT']) || $inQty > 0)
                ? 'STOCK_IN'
                : 'STOCK_OUT';

            return [
                'date' => $tx->date->toDateString(),
                'lot' => $tx->lot_number ?: '—',
                'party' => $partyName ?: '—',
                'vendor_customer' => $partyName ?: '—',
                'type' => $displayType,
                'in' => $inQty,
                'out' => $outQty,
                'stock_in' => $inQty,
                'stock_out' => $outQty,
                'vehicle' => $vehicleNumber ?: '—',
                'transaction_number' => $tx->transaction_number,
                'branch' => $tx->branch?->name,
                'branch_id' => $tx->branch_id,
                'item' => $tx->item?->name,
                'item_id' => $tx->item_id,
                'unit' => $tx->item?->unit?->code,
                'opening_balance' => round($opening, 3),
                'closing_balance' => round($closing, 3),
            ];
        });

        $totClosing = array_sum($latestClosingByKey);

        $company = app(\App\Services\PdfService::class)->resolveCompany($request->branch_id ?? null);
        $branchName = $request->filled('branch_id') ? \App\Models\Branch::find($request->branch_id)?->branch_name : null;
        $itemName = $request->filled('item_id') ? \App\Models\Item::find($request->item_id)?->name : null;

        $pdf = Pdf::loadView('pdf.stock_ledger', [
            'rows' => $rows,
            'company' => $company,
            'dateFrom' => $request->date_from,
            'dateTo' => $request->date_to,
            'branchName' => $branchName,
            'itemName' => $itemName,
            'totIn' => $totIn,
            'totOut' => $totOut,
            'totClosing' => $totClosing,
        ])->setPaper('a4', 'landscape');

        $filename = 'Stock_Ledger';
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $filename .= '_' . $request->date_from . '_to_' . $request->date_to;
        } elseif ($request->filled('date_from')) {
            $filename .= '_from_' . $request->date_from;
        } elseif ($request->filled('date_to')) {
            $filename .= '_upto_' . $request->date_to;
        } else {
            $filename .= '_' . date('Ymd_His');
        }
        $filename .= '.pdf';

        return $pdf->stream($filename);
    }

    public function lowStock(Request $request)
    {
        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        if (($ids = $this->allowedBranchIds()) && ! $branchId) {
            $rows = collect($this->service->currentStock(null));
            // sum across allowed branches per item
            $aggregated = [];
            foreach ($rows as $r) {
                $id = $r['item_id'];
                if (! isset($aggregated[$id])) {
                    $aggregated[$id] = $r;
                    $aggregated[$id]['available'] = 0;
                    $aggregated[$id]['total_in'] = 0;
                    $aggregated[$id]['total_out'] = 0;
                }
                $inq = InventoryTransaction::where('item_id', $id)->whereIn('branch_id', $ids)->where('status', 'POSTED');
                $in = (clone $inq)->where('type', 'STOCK_IN')->sum('quantity')
                    + (clone $inq)->where('type', 'TRANSFER_IN')->sum('quantity')
                    + (clone $inq)->where('type', 'ADJUSTMENT_IN')->sum('quantity');
                $out = (clone $inq)->where('type', 'STOCK_OUT')->sum('quantity')
                    + (clone $inq)->where('type', 'TRANSFER_OUT')->sum('quantity')
                    + (clone $inq)->where('type', 'ADJUSTMENT_OUT')->sum('quantity');
                $aggregated[$id]['available'] = round((float)$in - (float)$out, 3);
                $aggregated[$id]['total_in'] = round((float)$in, 3);
                $aggregated[$id]['total_out'] = round((float)$out, 3);
            }
            $low = array_values(array_filter($aggregated, fn ($r) => in_array($r['status'], ['Low Stock', 'Out of Stock'], true)));
            foreach ($low as &$row) {
                $avail = $row['available'];
                $min = (float) $row['min_stock_level'];
                $row['status'] = $avail <= 0 ? 'Out of Stock' : ($min > 0 && $avail <= $min ? 'Low Stock' : 'Normal');
            }
            return response()->json($low);
        }

        return response()->json(collect($this->service->currentStock($branchId))
            ->filter(fn ($r) => in_array($r['status'], ['Low Stock', 'Out of Stock'], true))
            ->values());
    }

    public function stockIn(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'branch_id' => ['required', 'exists:branches,id'],
            'item_id' => ['required', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'reference_type' => ['nullable', 'in:Settlement Bill,Purchase,Return,Opening Stock,Manual,Other,SETTLEMENT_BILL,PURCHASE,RETURN,OPENING,MANUAL,OTHER'],
            'reference_id' => ['nullable', 'integer'],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $map = [
                'Settlement Bill' => 'SETTLEMENT_BILL',
                'Purchase' => 'PURCHASE',
                'Return' => 'RETURN',
                'Opening Stock' => 'OPENING',
                'Manual' => 'MANUAL',
                'Other' => 'OTHER',
            ];
            if (isset($map[$data['reference_type'] ?? ''])) {
                $data['reference_type'] = $map[$data['reference_type']];
            }
            $tx = $this->service->stockIn($data, $request->user()->id);
            return response()->json($tx, 201);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function stockOut(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'branch_id' => ['required', 'exists:branches,id'],
            'item_id' => ['required', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'destination' => ['nullable', 'string', 'max:200'],
            'customer' => ['nullable', 'string', 'max:200'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $tx = $this->service->stockOut($data, $request->user()->id);
            return response()->json($tx, 201);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    /** Lot-aware stock transfer (godown to godown) — new inventory engine. */
    public function transferLot(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'source_branch_id' => ['required', 'exists:branches,id'],
            'dest_branch_id' => ['required', 'exists:branches,id', 'different:source_branch_id'],
            'lot_id' => ['required', 'exists:lots,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $data['company_id'] = $data['company_id'] ?? $this->activeCompanyId();

        try {
            $result = $this->ledger->transfer($data, $request->user()->id);
            return response()->json(['success' => true, 'data' => $result], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function transfers(Request $request)
    {
        $query = StockTransfer::with(['item.unit', 'unit', 'fromBranch', 'toBranch', 'vehicle'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->from_branch_id);
        }
        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->to_branch_id);
        }
        if ($request->filled('branch_id')) {
            $bId = $request->branch_id;
            $query->where(fn ($q) => $q->where('from_branch_id', $bId)->orWhere('to_branch_id', $bId));
        }
        if ($request->filled('item_id')) {
            $query->where('item_id', $request->item_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('transfer_number', 'like', "%$s%")
                    ->orWhere('lot_number', 'like', "%$s%")
                    ->orWhere('remarks', 'like', "%$s%")
                    ->orWhereHas('vehicle', fn ($vq) => $vq->where('vehicle_number', 'like', "%$s%"))
                    ->orWhereHas('item', fn ($iq) => $iq->where('name', 'like', "%$s%"));
            });
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate($request->get('per_page', 25)));
    }

    public function transfersPdf(Request $request)
    {
        $query = StockTransfer::with(['item.unit', 'unit', 'fromBranch', 'toBranch', 'vehicle'])
            ->orderBy('date')
            ->orderBy('id');

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->from_branch_id);
        }
        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->to_branch_id);
        }
        if ($request->filled('branch_id')) {
            $bId = $request->branch_id;
            $query->where(fn ($q) => $q->where('from_branch_id', $bId)->orWhere('to_branch_id', $bId));
        }
        if ($request->filled('item_id')) {
            $query->where('item_id', $request->item_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('transfer_number', 'like', "%$s%")
                    ->orWhere('lot_number', 'like', "%$s%")
                    ->orWhere('remarks', 'like', "%$s%")
                    ->orWhereHas('vehicle', fn ($vq) => $vq->where('vehicle_number', 'like', "%$s%"))
                    ->orWhereHas('item', fn ($iq) => $iq->where('name', 'like', "%$s%"));
            });
        }

        $transfers = $query->get();
        $totQuantity = (float) $transfers->sum('quantity');

        $branchId = $request->branch_id ?: $request->from_branch_id;
        $company = app(\App\Services\PdfService::class)->resolveCompany($branchId);
        $branchName = $request->branch_id ? \App\Models\Branch::find($request->branch_id)?->branch_name : null;
        $itemName = $request->item_id ? \App\Models\Item::find($request->item_id)?->name : null;

        $pdf = Pdf::loadView('pdf.stock_transfers', [
            'transfers' => $transfers,
            'company' => $company,
            'dateFrom' => $request->date_from,
            'dateTo' => $request->date_to,
            'branchName' => $branchName,
            'itemName' => $itemName,
            'totQuantity' => $totQuantity,
        ])->setPaper('a4', 'landscape');

        $filename = 'Stock_Transfers_Report';
        if ($request->date_from && $request->date_to) {
            $filename .= '_' . $request->date_from . '_to_' . $request->date_to;
        } elseif ($request->date_from) {
            $filename .= '_from_' . $request->date_from;
        } elseif ($request->date_to) {
            $filename .= '_upto_' . $request->date_to;
        } else {
            $filename .= '_' . date('Ymd_His');
        }
        $filename .= '.pdf';

        return $pdf->stream($filename);
    }

    public function transfer(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_branch_id' => ['required', 'exists:branches,id'],
            'to_branch_id' => ['required', 'exists:branches,id', 'different:from_branch_id'],
            'item_id' => ['required', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'vehicle_id' => ['nullable'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'vehicle' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $vNum = trim($request->input('vehicle_number') ?? $request->input('vehicle') ?? '');
        if ($vNum !== '') {
            $companyId = $this->activeCompanyId() ?? 1;
            $veh = \App\Models\Vehicle::where('vehicle_number', $vNum)->first();
            if (!$veh) {
                $veh = \App\Models\Vehicle::create([
                    'vehicle_number' => $vNum,
                    'status' => 'ACTIVE',
                    'company_id' => $companyId,
                ]);
            }
            $data['vehicle_id'] = $veh->id;
        }

        // Auto-resolve unit_id from item if not provided
        if (empty($data['unit_id']) && !empty($data['item_id'])) {
            $data['unit_id'] = \App\Models\Item::whereKey($data['item_id'])->value('unit_id');
        }

        try {
            $transfer = $this->service->transfer($data, $request->user()->id);
            return response()->json($transfer, 201);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function adjustment(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'branch_id' => ['required', 'exists:branches,id'],
            'item_id' => ['required', 'exists:items,id'],
            'direction' => ['required', 'in:IN,OUT'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $adj = $this->service->adjust($data, $request->user()->id);
            return response()->json($adj, 201);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }

    public function partySummary(Request $request)
    {
        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        $vendorId = $request->vendor_id ? (int) $request->vendor_id : null;
        $itemId = $request->item_id ? (int) $request->item_id : null;

        // Query ONLY physical InventoryTransactions (STRICTLY EXCLUDE Settlement bills)
        $txQuery = InventoryTransaction::with(['vendor', 'item', 'branch', 'vehicle'])
            ->where('status', 'POSTED')
            ->where(function ($q) {
                $q->whereNull('reference_type')
                  ->orWhereNotIn('reference_type', ['SETTLEMENT_BILL', 'Settlement Bill', 'SETTLEMENT']);
            });

        $this->scopeBranches($txQuery);
        $this->scopeCompany($txQuery);
        $this->scopeActiveVendorTransactions($txQuery);

        if ($branchId) {
            $txQuery->where('branch_id', $branchId);
        }
        if ($vendorId) {
            $txQuery->where(function ($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId)->orWhere('party_id', $vendorId);
            });
        }
        if ($itemId) {
            $txQuery->where('item_id', $itemId);
        }
        if ($request->filled('date_from')) {
            $txQuery->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $txQuery->whereDate('date', '<=', $request->date_to);
        }

        $transactions = $txQuery->get();

        $partyMap = [];
        $godownMap = [];
        $recentMovements = [];

        // Process Physical Inventory Transactions
        foreach ($transactions as $tx) {
            $vId = $tx->vendor_id ?? $tx->party_id ?? 0;
            $v = $tx->vendor;
            $vName = $v?->vendor_name ?? $v?->name ?? ($tx->customer ?: 'General / Direct');
            $vCode = $v?->vendor_code ?? ($vId ? 'V-'.$vId : 'DIRECT');
            $pId = $tx->item_id ?? 0;
            $pName = $tx->item?->name ?? 'Agro Product';
            $gId = $tx->branch_id;
            $gName = $tx->branch?->branch_name ?? $tx->branch?->name ?? 'Godown #'.$gId;

            $mt = (float) $tx->quantity;
            $bags = (float) ($tx->bags_count ?? 0);
            $isIn = in_array($tx->type, ['STOCK_IN', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'OPENING']);

            $key = $gId . '_' . $vId . '_' . $pId;

            if (!isset($partyMap[$key])) {
                $partyMap[$key] = [
                    'branch_id' => $gId,
                    'branch_name' => $gName,
                    'vendor_id' => $vId,
                    'vendor_name' => $vName,
                    'vendor_code' => $vCode,
                    'product_id' => $pId,
                    'product_name' => $pName,
                    'item_id' => $pId,
                    'item_name' => $pName,
                    'inlet_mt' => 0,
                    'inlet_bags' => 0,
                    'outlet_mt' => 0,
                    'outlet_bags' => 0,
                    'movements' => [],
                ];
            }

            if ($isIn) {
                $partyMap[$key]['inlet_mt'] += $mt;
                $partyMap[$key]['inlet_bags'] += $bags;
            } else {
                $partyMap[$key]['outlet_mt'] += $mt;
                $partyMap[$key]['outlet_bags'] += $bags;
            }

            $movementItem = [
                'date' => $tx->date ? (is_string($tx->date) ? $tx->date : $tx->date->toDateString()) : $tx->created_at->toDateString(),
                'type' => $isIn ? 'IMPORT_INLET' : 'EXPORT_OUTLET',
                'ref_no' => $tx->transaction_number ?? 'TX-'.$tx->id,
                'source' => $isIn ? ('Vendor: ' . $vName) : ('Godown: ' . $gName),
                'destination' => $isIn ? ('Godown: ' . $gName) : ($tx->destination ?: $tx->customer ?: 'Market Outlet'),
                'quantity_mt' => round($mt, 3),
                'quantity_bags' => (int) $bags,
                'vehicle' => $tx->vehicle?->vehicle_number ?? 'N/A',
                'remarks' => $tx->remarks ?? $tx->type,
            ];

            $partyMap[$key]['movements'][] = $movementItem;
            $recentMovements[] = $movementItem;

            if (!isset($godownMap[$gId])) {
                $godownMap[$gId] = [
                    'branch_id' => $gId,
                    'branch_name' => $gName,
                    'total_inlet_mt' => 0,
                    'total_inlet_bags' => 0,
                    'total_outlet_mt' => 0,
                    'total_outlet_bags' => 0,
                    'vendors_count' => 0,
                ];
            }
            if ($isIn) {
                $godownMap[$gId]['total_inlet_mt'] += $mt;
                $godownMap[$gId]['total_inlet_bags'] += $bags;
            } else {
                $godownMap[$gId]['total_outlet_mt'] += $mt;
                $godownMap[$gId]['total_outlet_bags'] += $bags;
            }
        }

        // Format final payload
        $partyList = [];
        $totalInletMt = 0;
        $totalInletBags = 0;
        $totalOutletMt = 0;
        $totalOutletBags = 0;

        foreach ($partyMap as $item) {
            $inletMt = round($item['inlet_mt'], 3);
            $inletBags = (int) $item['inlet_bags'];
            $outletMt = round($item['outlet_mt'], 3);
            $outletBags = (int) $item['outlet_bags'];

            $holdingMt = round($inletMt - $outletMt, 3);
            $holdingBags = $inletBags - $outletBags;

            $partyList[] = [
                'branch_id' => $item['branch_id'],
                'branch_name' => $item['branch_name'],
                'vendor_id' => $item['vendor_id'],
                'vendor_name' => $item['vendor_name'],
                'vendor_code' => $item['vendor_code'],
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'item_id' => $item['item_id'],
                'item_name' => $item['item_name'],
                'inlet_mt' => $inletMt,
                'inlet_bags' => $inletBags,
                'outlet_mt' => $outletMt,
                'outlet_bags' => $outletBags,
                'holding_mt' => $holdingMt,
                'holding_bags' => $holdingBags,
                'movements' => array_slice($item['movements'], -20),
            ];

            $totalInletMt += $inletMt;
            $totalInletBags += $inletBags;
            $totalOutletMt += $outletMt;
            $totalOutletBags += $outletBags;
        }

        // Build godown list with embedded vendors
        $godownList = [];
        foreach ($godownMap as $gId => $g) {
            $gInMt = round($g['total_inlet_mt'], 3);
            $gInBags = (int) $g['total_inlet_bags'];
            $gOutMt = round($g['total_outlet_mt'], 3);
            $gOutBags = (int) $g['total_outlet_bags'];

            $gVendors = array_values(array_filter($partyList, fn ($p) => $p['branch_id'] == $gId));

            $godownList[] = [
                'branch_id' => $g['branch_id'],
                'branch_name' => $g['branch_name'],
                'total_inlet_mt' => $gInMt,
                'total_inlet_bags' => $gInBags,
                'total_outlet_mt' => $gOutMt,
                'total_outlet_bags' => $gOutBags,
                'current_holding_mt' => round($gInMt - $gOutMt, 3),
                'current_holding_bags' => $gInBags - $gOutBags,
                'vendors_count' => count(array_unique(array_column($gVendors, 'vendor_id'))),
                'vendors' => $gVendors,
            ];
        }

        usort($recentMovements, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return response()->json([
            'summary' => [
                'total_inlet_mt' => round($totalInletMt, 3),
                'total_inlet_bags' => (int) $totalInletBags,
                'total_outlet_mt' => round($totalOutletMt, 3),
                'total_outlet_bags' => (int) $totalOutletBags,
                'net_holding_mt' => round($totalInletMt - $totalOutletMt, 3),
                'net_holding_bags' => (int) ($totalInletBags - $totalOutletBags),
                'active_parties' => count(array_unique(array_column($partyList, 'vendor_id'))),
                'active_godowns' => count($godownList),
            ],
            'parties' => $partyList,
            'godowns' => $godownList,
            'recent_movements' => array_slice($recentMovements, 0, 30),
        ]);
    }

    public function partySummaryPdf(Request $request)
    {
        $response = $this->partySummary($request);
        $data = $response->getData(true);

        $company = app(\App\Services\PdfService::class)->resolveCompany($request->branch_id ?? null);

        $pdf = Pdf::loadView('pdf.stock_summary', [
            'summary' => $data['summary'] ?? [],
            'parties' => $data['parties'] ?? [],
            'godowns' => $data['godowns'] ?? [],
            'company' => $company,
            'dateFrom' => $request->date_from,
            'dateTo' => $request->date_to,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('Stock_Summary_' . date('Ymd_His') . '.pdf');
    }
}
