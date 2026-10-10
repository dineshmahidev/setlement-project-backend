<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $query = ItemCategory::orderBy('name');
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }
        if ($request->boolean('all')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:item_categories,name'],
            'code' => ['nullable', 'string', 'max:30', 'unique:item_categories,code'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);
        $cat = ItemCategory::create($data);
        $this->audit->log('CREATE', 'Category', $cat->id, null, $cat->toArray());
        return response()->json($cat, 201);
    }

    public function show(ItemCategory $category)
    {
        return response()->json($category);
    }

    public function update(Request $request, ItemCategory $category)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'unique:item_categories,name,'.$category->id],
            'code' => ['nullable', 'string', 'max:30', 'unique:item_categories,code,'.$category->id],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);
        $old = $category->toArray();
        $category->update($data);
        $this->audit->log('UPDATE', 'Category', $category->id, $old, $category->toArray());
        return response()->json($category);
    }

    public function destroy(ItemCategory $category)
    {
        $category->update(['status' => 'INACTIVE']);
        return response()->json(['message' => 'Category archived']);
    }
}
