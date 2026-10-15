<?php

namespace App\Http\Controllers;

use App\Models\Lot;
use App\Services\InventoryLedgerService;
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
            ->where(function ($q) use ($lot) {
                if ($lot->company_id) {
                    $q->where('company_id', $lot->company_id);
                }
            })
            ->where('branch_id', $lot->branch_id)
            ->where('item_id', $lot->item_id)
            ->where('lot_number', $lot->lot_number)
            ->orderBy('date')->orderBy('id')->get();

        $lotData = $lot->toArray();
        $lotData['lot'] = $lot;
        $lotData['movements'] = $movements;

        return response()->json([
            'success' => true,
            'data' => $lotData,
            ...$lotData,
        ]);
    }
}
