<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $query = Item::with(['category', 'unit'])->orderBy('name');
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
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:items,code'],
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'exists:item_categories,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $item = Item::create($data);
        $this->audit->log('CREATE', 'Item', $item->id, null, $item->toArray());
        return response()->json($item->load(['category', 'unit']), 201);
    }

    public function show(Item $item)
    {
        return response()->json($item->load(['category', 'unit']));
    }

    public function update(Request $request, Item $item)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:30', 'unique:items,code,'.$item->id],
            'name' => ['sometimes', 'string', 'max:200'],
            'category_id' => ['nullable', 'exists:item_categories,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $old = $item->toArray();
        $item->update($data);
        $this->audit->log('UPDATE', 'Item', $item->id, $old, $item->toArray());
        return response()->json($item->load(['category', 'unit']));
    }

    public function destroy(Item $item)
    {
        $this->audit->log('ARCHIVE', 'Item', $item->id, $item->toArray(), ['status' => 'INACTIVE']);
        $item->update(['status' => 'INACTIVE']);
        return response()->json(['message' => 'Item archived']);
    }
}
