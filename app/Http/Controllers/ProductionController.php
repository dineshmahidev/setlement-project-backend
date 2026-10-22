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
        $q = ProductionBatch::with(['party:id,vendor_name', 'sourceBranch:id,branch_name', 'destBranch:id,branch_name', 'outputItem:id,code,name', 'outputGrade:id,code,name'])
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
        $data = $request->validate([
            'party_id' => 'nullable|exists:vendors,id',
            'source_branch_id' => 'nullable|exists:branches,id',
            'dest_branch_id' => 'required|exists:branches,id',
            'date' => 'required|date',
            'output_item_id' => 'required|exists:items,id',
            'output_grade_id' => 'nullable|exists:grades,id',
            'output_lot_number' => 'nullable|string|max:100',
            'output_oil_pct' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
            'inputs' => 'required|array|min:1',
            'inputs.*.lot_id' => 'required|exists:lots,id',
            'inputs.*.item_id' => 'nullable|exists:items,id',
            'inputs.*.quantity' => 'required|numeric|gt:0',
            'inputs.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'outputs' => 'required|array|min:1',
            'outputs.*.item_id' => 'required|exists:items,id',
            'outputs.*.quantity' => 'required|numeric|gt:0',
            'outputs.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'outputs.*.grade_id' => 'nullable|exists:grades,id',
        ]);

        $companyId = $this->activeCompanyId() ?? \App\Models\Branch::find($data['dest_branch_id'])?->company_id ?? 1;
        $userId = (int) (auth()->id() ?? $request->user()?->id ?? 1);

        $batch = DB::transaction(function () use ($data, $companyId, $userId) {
            $batchNumber = $this->ledger->nextNumber('PRD', 'production_batches', 'batch_number');
            $batch = ProductionBatch::create([
                'batch_number' => $batchNumber,
                'company_id' => $companyId,
                'party_id' => $data['party_id'] ?? null,
                'source_branch_id' => $data['source_branch_id'] ?? null,
                'dest_branch_id' => $data['dest_branch_id'],
                'date' => $data['date'],
                'output_item_id' => $data['output_item_id'],
                'output_grade_id' => $data['output_grade_id'] ?? null,
                'output_lot_number' => $data['output_lot_number'] ?? null,
                'output_oil_pct' => $data['output_oil_pct'] ?? null,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? $data['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['inputs'] as $i) {
                if (empty($i['item_id'])) {
                    $i['item_id'] = \App\Models\Lot::find($i['lot_id'])?->item_id ?? $data['output_item_id'];
                }
                $batch->inputs()->create($i);
            }

            foreach ($data['outputs'] as $o) {
                $batch->outputs()->create($o);
            }

            return $batch;
        });

        // Auto-confirm production batch so inputs are consumed & output lot created
        try {
            $batch = $this->ledger->confirmProduction($batch, $userId);
        } catch (\Throwable $e) {
            throw $e;
        }

        return response()->json($batch->load(['party:id,vendor_name', 'inputs.lot:id,lot_number,available_qty', 'outputs.item:id,code,name']), 201);
    }

    public function show(ProductionBatch $production)
    {
        $production->load(['party:id,vendor_name,mobile,city', 'inputs.lot:id,lot_number,available_qty,branch_id,item_id', 'inputs.item:id,code,name', 'outputs.item:id,code,name', 'outputs.grade:id,code,name', 'outputs.lot:id,lot_number,available_qty', 'sourceBranch:id,branch_name', 'destBranch:id,branch_name', 'outputItem:id,code,name', 'outputGrade:id,code,name', 'creator:id,name', 'confirmer:id,name']);
        return response()->json($production);
    }

    public function update(Request $request, ProductionBatch $production)
    {
        $data = $request->validate([
            'party_id' => 'nullable|exists:vendors,id',
            'source_branch_id' => 'nullable|exists:branches,id',
            'dest_branch_id' => 'required|exists:branches,id',
            'date' => 'required|date',
            'output_item_id' => 'required|exists:items,id',
            'output_grade_id' => 'nullable|exists:grades,id',
            'output_lot_number' => 'nullable|string|max:100',
            'output_oil_pct' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
            'inputs' => 'required|array|min:1',
            'inputs.*.lot_id' => 'required|exists:lots,id',
            'inputs.*.item_id' => 'nullable|exists:items,id',
            'inputs.*.quantity' => 'required|numeric|gt:0',
            'inputs.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'outputs' => 'required|array|min:1',
            'outputs.*.item_id' => 'required|exists:items,id',
            'outputs.*.quantity' => 'required|numeric|gt:0',
            'outputs.*.oil_pct' => 'nullable|numeric|min:0|max:100',
            'outputs.*.grade_id' => 'nullable|exists:grades,id',
        ]);

        $userId = (int) (auth()->id() ?? $request->user()?->id ?? 1);
        $wasConfirmed = ($production->status === 'CONFIRMED');

        try {
            $updatedBatch = DB::transaction(function () use ($production, $data, $wasConfirmed, $userId) {
                // If previously confirmed, reverse previous stock consumption & outputs
                if ($wasConfirmed) {
                    // Check if output lot was already dispatched / used
                    foreach ($production->outputs as $out) {
                        if ($out->lot_id) {
                            $outLot = \App\Models\Lot::find($out->lot_id);
                            if ($outLot && (float) $outLot->issued_qty > 0) {
                                throw new \RuntimeException(sprintf('Output lot %s has already been partially dispatched/used (%s units). This batch cannot be edited.', $outLot->lot_number, $outLot->issued_qty));
                            }
                        }
                    }

                    // Replenish consumed input lots back into stock
                    foreach ($production->inputs as $in) {
                        $lot = \App\Models\Lot::find($in->lot_id);
                        if ($lot) {
                            $avail = (float) $lot->available_qty + (float) $in->quantity;
                            $issued = max(0, (float) $lot->issued_qty - (float) $in->quantity);
                            $cost = (float) $lot->unit_cost;
                            $lot->update([
                                'issued_qty' => $issued,
                                'available_qty' => $avail,
                                'stock_value' => round(max(0, $avail * $cost), 2),
                                'status' => 'OPEN',
                            ]);
                        }
                    }

                    // Remove existing output lot(s)
                    foreach ($production->outputs as $out) {
                        if ($out->lot_id) {
                            $outLot = \App\Models\Lot::find($out->lot_id);
                            if ($outLot) {
                                $outLot->forceDelete();
                            }
                        }
                    }

                    // Delete old ledger movements for this production batch
                    \App\Models\InventoryTransaction::where('reference_type', 'PRODUCTION')
                        ->where('reference_id', $production->id)
                        ->delete();
                }

                // Delete old child records
                $production->inputs()->delete();
                $production->outputs()->delete();

                // Update batch header
                $production->update([
                    'party_id' => $data['party_id'] ?? null,
                    'source_branch_id' => $data['source_branch_id'] ?? null,
                    'dest_branch_id' => $data['dest_branch_id'],
                    'date' => $data['date'],
                    'output_item_id' => $data['output_item_id'],
                    'output_grade_id' => $data['output_grade_id'] ?? null,
                    'output_lot_number' => $data['output_lot_number'] ?? null,
                    'output_oil_pct' => $data['output_oil_pct'] ?? null,
                    'status' => 'DRAFT',
                    'notes' => $data['notes'] ?? $data['remarks'] ?? null,
                ]);

                // Create new inputs
                foreach ($data['inputs'] as $i) {
                    if (empty($i['item_id'])) {
                        $i['item_id'] = \App\Models\Lot::find($i['lot_id'])?->item_id ?? $data['output_item_id'];
                    }
                    $production->inputs()->create($i);
                }

                // Create new outputs
                foreach ($data['outputs'] as $o) {
                    $production->outputs()->create($o);
                }

                // Auto-confirm batch again to post fresh movements
                return $this->ledger->confirmProduction($production, $userId);
            });

            return response()->json($updatedBatch->load(['party:id,vendor_name', 'inputs.lot:id,lot_number,available_qty', 'outputs.item:id,code,name']));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
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

    public function destroy(ProductionBatch $production)
    {
        try {
            DB::transaction(function () use ($production) {
                if ($production->status === 'CONFIRMED') {
                    // Check if output lot was already dispatched / used
                    foreach ($production->outputs as $out) {
                        if ($out->lot_id) {
                            $outLot = \App\Models\Lot::find($out->lot_id);
                            if ($outLot && (float) $outLot->issued_qty > 0) {
                                throw new \RuntimeException(sprintf('Output lot %s has already been partially dispatched/used (%s units). This batch cannot be deleted.', $outLot->lot_number, $outLot->issued_qty));
                            }
                        }
                    }

                    // Replenish consumed input lots back into stock
                    foreach ($production->inputs as $in) {
                        $lot = \App\Models\Lot::find($in->lot_id);
                        if ($lot) {
                            $avail = (float) $lot->available_qty + (float) $in->quantity;
                            $issued = max(0, (float) $lot->issued_qty - (float) $in->quantity);
                            $cost = (float) $lot->unit_cost;
                            $lot->update([
                                'issued_qty' => $issued,
                                'available_qty' => $avail,
                                'stock_value' => round(max(0, $avail * $cost), 2),
                                'status' => 'OPEN',
                            ]);
                        }
                    }

                    // Delete output lot(s)
                    foreach ($production->outputs as $out) {
                        if ($out->lot_id) {
                            $outLot = \App\Models\Lot::find($out->lot_id);
                            if ($outLot) {
                                $outLot->forceDelete();
                            }
                        }
                    }

                    // Void ledger movements
                    \App\Models\InventoryTransaction::where('reference_type', 'PRODUCTION')
                        ->where('reference_id', $production->id)
                        ->delete();
                }

                $production->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Production batch moved to trash successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function pdf(ProductionBatch $production)
    {
        $pdf = app(\App\Services\PdfService::class)->generateProductionPdf($production);
        $fileName = str_replace(['/', '\\'], '-', $production->batch_number) . '.pdf';
        return $pdf->stream($fileName);
    }
}
