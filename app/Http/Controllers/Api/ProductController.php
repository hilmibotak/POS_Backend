<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // ==========================================
    // GET ALL PRODUCTS
    // ==========================================

    public function index()
    {
        $products = Product::with([
            'category',
            'baseUnit',
            'productUnits.unit'
        ])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data produk berhasil diambil',
            'data' => $products,
        ]);
    }

    // ==========================================
    // CREATE PRODUCT
    // ==========================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'exists:categories,id'
            ],

            'name' => [
                'required',
                'string',
                'max:255'
            ],

            // BARU
            'brand' => [
                'nullable',
                'string',
                'max:255'
            ],

            // BARU
            'size' => [
                'nullable',
                'string',
                'max:255'
            ],

            'barcode' => [
                'nullable',
                'string',
                'max:255',
                'unique:products,barcode'
            ],

            'base_unit_id' => [
                'required',
                'exists:units,id'
            ],

            'purchase_price' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'selling_price' => [
                'required',
                'numeric',
                'min:0'
            ],

            'stock' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'minimum_stock' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'is_active' => [
                'nullable',
                'boolean'
            ],
        ]);

        $product = Product::create([
            'category_id' => $validated['category_id'],

            'name' => $validated['name'],

            // BARU
            'brand' => $validated['brand'] ?? null,

            // BARU
            'size' => $validated['size'] ?? null,

            'barcode' => $validated['barcode'] ?? null,

            'base_unit_id' => $validated['base_unit_id'],

            'purchase_price' =>
                $validated['purchase_price'] ?? 0,

            'selling_price' =>
                $validated['selling_price'],

            'stock' =>
                $validated['stock'] ?? 0,

            'minimum_stock' =>
                $validated['minimum_stock'] ?? 0,

            'is_active' =>
                $validated['is_active'] ?? true,
        ]);

        // Load relationship
        $product->load([
            'category',
            'baseUnit',
            'productUnits.unit'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan',
            'data' => $product,
        ], 201);
    }

    // ==========================================
    // GET DETAIL PRODUCT
    // ==========================================

    public function show(Product $product)
    {
        $product->load([
            'category',
            'baseUnit',
            'productUnits.unit'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail produk berhasil diambil',
            'data' => $product,
        ]);
    }

    // ==========================================
    // UPDATE PRODUCT
    // ==========================================

    public function update(
        Request $request,
        Product $product
    ) {
        $validated = $request->validate([
            'category_id' => [
                'required',
                'exists:categories,id'
            ],

            'name' => [
                'required',
                'string',
                'max:255'
            ],

            // BARU
            'brand' => [
                'nullable',
                'string',
                'max:255'
            ],

            // BARU
            'size' => [
                'nullable',
                'string',
                'max:255'
            ],

            'barcode' => [
                'nullable',
                'string',
                'max:255',
                'unique:products,barcode,' . $product->id
            ],

            'base_unit_id' => [
                'required',
                'exists:units,id'
            ],

            'purchase_price' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'selling_price' => [
                'required',
                'numeric',
                'min:0'
            ],

            'stock' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'minimum_stock' => [
                'nullable',
                'numeric',
                'min:0'
            ],

            'is_active' => [
                'nullable',
                'boolean'
            ],
        ]);

        $product->update($validated);

        $product->load([
            'category',
            'baseUnit',
            'productUnits.unit'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diperbarui',
            'data' => $product,
        ]);
    }

    // ==========================================
    // DEACTIVATE PRODUCT
    // ==========================================

    public function destroy(Product $product)
    {
        $product->update([
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dinonaktifkan',
            'data' => $product,
        ]);
    }
}