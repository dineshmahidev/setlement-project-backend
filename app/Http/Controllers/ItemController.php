<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $query = $this->scopeCompany(Item::query())->with(['category', 'unit'])->orderBy('name');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('all')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('items', 'code')
                    ->where(fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q->whereNull('company_id'))
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'material_type' => ['nullable', 'string', 'max:30'],
            'default_oil_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:item_categories,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        if (empty($data['code'])) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $data['name'] ?? 'ITM'), 0, 4));
            if (strlen($prefix) < 2) {
                $prefix = 'ITM';
            }
            $codeCandidate = $prefix . '-' . str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT);
            while (Item::where('company_id', $companyId)->where('code', $codeCandidate)->exists()) {
                $codeCandidate = $prefix . '-' . rand(1000, 9999);
            }
            $data['code'] = $codeCandidate;
        }

        $item = Item::create($data + ['company_id' => $companyId]);
        $this->audit->log('CREATE', 'Item', $item->id, null, $item->toArray());
        return response()->json($item->load(['category', 'unit']), 201);
    }

    public function show(Item $item)
    {
        $this->guardCompanyOwnership($item);

        return response()->json($item->load(['category', 'unit']));
    }

    public function update(Request $request, Item $item)
    {
        $this->guardCompanyOwnership($item);

        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('items', 'code')
                    ->ignore($item->id)
                    ->where(fn ($q) => $item->company_id ? $q->where('company_id', $item->company_id) : $q->whereNull('company_id'))
                    ->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'string', 'max:200'],
            'material_type' => ['nullable', 'string', 'max:30'],
            'default_oil_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:item_categories,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        if (array_key_exists('code', $data) && empty($data['code'])) {
            unset($data['code']);
        }

        $old = $item->toArray();
        $item->update($data);
        $this->audit->log('UPDATE', 'Item', $item->id, $old, $item->toArray());
        return response()->json($item->load(['category', 'unit']));
    }

    public function destroy(Item $item)
    {
        $this->guardCompanyOwnership($item);

        $mappedReasons = [];

        $inwardCount = \App\Models\InwardItem::where('item_id', $item->id)->count();
        if ($inwardCount > 0) {
            $mappedReasons[] = "{$inwardCount} Inward entry item(s)";
        }

        $outwardCount = \App\Models\OutwardItem::where('item_id', $item->id)->count();
        if ($outwardCount > 0) {
            $mappedReasons[] = "{$outwardCount} Outward entry item(s)";
        }

        $txCount = \App\Models\InventoryTransaction::where('item_id', $item->id)->count();
        if ($txCount > 0) {
            $mappedReasons[] = "{$txCount} Stock/Inventory transaction(s)";
        }

        $lotCount = \App\Models\Lot::where('item_id', $item->id)->count();
        if ($lotCount > 0) {
            $mappedReasons[] = "{$lotCount} Lot(s)";
        }

        $transferCount = \App\Models\StockTransfer::where('item_id', $item->id)->count();
        if ($transferCount > 0) {
            $mappedReasons[] = "{$transferCount} Stock transfer(s)";
        }

        $adjCount = \App\Models\StockAdjustment::where('item_id', $item->id)->count();
        if ($adjCount > 0) {
            $mappedReasons[] = "{$adjCount} Stock adjustment(s)";
        }

        $prodBatchCount = \App\Models\ProductionBatch::where('output_item_id', $item->id)->count();
        if ($prodBatchCount > 0) {
            $mappedReasons[] = "{$prodBatchCount} Production batch output(s)";
        }

        $prodInputCount = \App\Models\ProductionInput::where('item_id', $item->id)->count();
        if ($prodInputCount > 0) {
            $mappedReasons[] = "{$prodInputCount} Production input(s)";
        }

        $prodOutputCount = \App\Models\ProductionOutput::where('item_id', $item->id)->count();
        if ($prodOutputCount > 0) {
            $mappedReasons[] = "{$prodOutputCount} Production output(s)";
        }

        $vehCount = \App\Models\VehicleMovement::where('item_id', $item->id)->count();
        if ($vehCount > 0) {
            $mappedReasons[] = "{$vehCount} Vehicle movement(s)";
        }

        if (!empty($mappedReasons)) {
            $details = implode(', ', $mappedReasons);
            return response()->json([
                'success' => false,
                'message' => "Cannot delete item \"{$item->name}\": It is currently mapped to {$details}. Please manually delete or remove these mapped records first before deleting this item.",
                'mapped_records' => $mappedReasons,
            ], 422);
        }

        $old = $item->toArray();
        $this->audit->log('DELETE', 'Item', $item->id, $old, null);
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => "Item \"{$item->name}\" deleted successfully",
        ]);
    }
}
