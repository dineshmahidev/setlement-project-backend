<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->scopeCompany(Product::query());

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%")
                  ->orWhere('product_type', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('all')) {
            return response()->json([
                'success' => true,
                'data' => $query->orderBy('product_name')->get(),
            ]);
        }

        $products = $query->orderBy('product_name')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    public function store(ProductRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = $this->activeCompanyId();

        if (empty($data['product_code'])) {
            $code = 'PROD-' . random_int(100, 999);
            while (Product::where('product_code', $code)->exists()) {
                $code = 'PROD-' . random_int(1000, 99999);
            }
            $data['product_code'] = $code;
        }

        $product = Product::create($data);

        AuditLog::logAction('created', 'Product', $product->id, $product->product_code, null, $product->toArray(), 'Product created');

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product,
        ], 201);
    }

    public function show(Product $product)
    {
        $this->guardCompanyOwnership($product);

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    public function update(ProductRequest $request, Product $product)
    {
        $this->guardCompanyOwnership($product);
        $old = $product->toArray();
        $product->update($request->validated());

        AuditLog::logAction('updated', 'Product', $product->id, $product->product_code, $old, $product->toArray(), 'Product updated');

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product,
        ]);
    }

    public function destroy(Product $product)
    {
        $this->guardCompanyOwnership($product);

        if ($product->settlements()->exists() || $product->invoices()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete product linked to existing invoices or settlements.',
            ], 422);
        }

        $old = $product->toArray();
        $product->delete();

        AuditLog::logAction('deleted', 'Product', $product->id, $product->product_code, $old, null, 'Product deleted');

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }
}
