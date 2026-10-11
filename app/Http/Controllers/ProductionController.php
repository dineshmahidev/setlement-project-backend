<?php

namespace App\Http\Controllers;

use App\Models\ProductionBatch;
use App\Services\InventoryLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionController extends Controller
{
    public function __construct(private InventoryLedgerService $ledger) {}

    public function index(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $q = ProductionBatch::with(['sourceBranch:id,branch_name', 'destBranch:id,branch_name', 'outputItem:id,code,name', 'outputGrade:id,code,name'])
            ->orderByDesc('id');
        if ($companyId) $q->where('company_id', $companyId);
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('from_date')) $q->whereDate('date', '>=', $request->from_date);
        if ($request->filled('to_date')) $q->whereDate('date', '<=', $request->to_date);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($qq) => $qq->where('batch_number', 'like', "%$s%")->orWhere('output_lot_number', 'like', "%$s%"));
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $data = $request->validate([
            'source_branch_id' => 'nullable|exists:branches,id',
            'dest_branch_id' => 'required|exists:branches,id',
            'date' => 'required|date',
            'output_item_id' => 'required|exists:items,id',
            'output_grade_id' => 'nullable|exists:grades,id',
            'output_lot_number' => 'nullable|string|max:100',
            'output_oil_pct' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'inputs' => 'required|array|min:1',
            'inputs.*.lot_id' => 'required|exists:lots,id',
            'inputs.*.item_id' => 'required|exists:items,id',
            'inputs.*.quantity' => 'required|numeric|gt:0',
            'inputs.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'outputs' => 'required|array|min:1',
            'outputs.*.item_id' => 'required|exists:items,id',
            'outputs.*.quantity' => 'required|numeric|gt:0',
            'outputs.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'outputs.*.grade_id' => 'nullable|exists:grades,id',
        ]);

        $batch = DB::transaction(function () use ($data, $companyId) {
            $batchNumber = $this->ledger->nextNumber('PRD', 'production_batches', 'batch_number');
            $batch = ProductionBatch::create([
                'batch_number' => $batchNumber,
                'company_id' => $companyId,
                'source_branch_id' => $data['source_branch_id'] ?? null,
                'dest_branch_id' => $data['dest_branch_id'],
                'date' => $data['date'],
                'output_item_id' => $data['output_item_id'],
                'output_grade_id' => $data['output_grade_id'] ?? null,
                'output_lot_number' => $data['output_lot_number'] ?? null,
                'output_oil_pct' => $data['output_oil_pct'] ?? null,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'created_by' => request()->user()->id,
            ]);
            foreach ($data['inputs'] as $i) $batch->inputs()->create($i);
            foreach ($data['outputs'] as $o) $batch->outputs()->create($o);
            return $batch;
        });

        return response()->json($batch->load(['inputs.lot:id,lot_number,available_qty', 'outputs.item:id,code,name']), 201);
    }

    public function show(ProductionBatch $production)
    {
        $production->load(['inputs.lot:id,lot_number,available_qty,branch_id', 'inputs.item:id,code,name', 'outputs.item:id,code,name', 'outputs.grade:id,code,name', 'outputs.lot:id,lot_number', 'sourceBranch:id,branch_name', 'destBranch:id,branch_name', 'outputItem:id,code,name', 'outputGrade:id,code,name', 'creator:id,name', 'confirmer:id,name']);
        return response()->json($production);
    }

    public function confirm(Request $request, ProductionBatch $production)
    {
        try {
            $batch = $this->ledger->confirmProduction($production, $request->user()->id);
            return response()->json(['success' => true, 'data' => $batch]);
        } catch (\Throwable $e) {
            return $this->error($e, 422);
        }
    }
}
