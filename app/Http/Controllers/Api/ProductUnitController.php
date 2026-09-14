<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Http\Request;

class ProductUnitController extends Controller
{
    public function index(Product $product)
    {
        $productUnits = $product->productUnits()
            ->with('unit')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data satuan produk berhasil diambil',
            'data' => $productUnits,
        ]);
    }

    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'conversion_rate' => 'required|numeric|min:0.001',
            'selling_price' => 'required|numeric|min:0',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $productUnit = $product->productUnits()->create([
            'unit_id' => $validated['unit_id'],
            'conversion_rate' => $validated['conversion_rate'],
            'selling_price' => $validated['selling_price'],
            'is_default' => $validated['is_default'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $productUnit->load('unit');

        return response()->json([
            'success' => true,
            'message' => 'Satuan produk berhasil ditambahkan',
            'data' => $productUnit,
        ], 201);
    }

    public function show(Product $product, ProductUnit $productUnit)
    {
        $productUnit->load('unit');

        return response()->json([
            'success' => true,
            'message' => 'Detail satuan produk berhasil diambil',
            'data' => $productUnit,
        ]);
    }

    public function update(
        Request $request,
        Product $product,
        ProductUnit $productUnit
    ) {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'conversion_rate' => 'required|numeric|min:0.001',
            'selling_price' => 'required|numeric|min:0',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $productUnit->update($validated);

        $productUnit->load('unit');

        return response()->json([
            'success' => true,
            'message' => 'Satuan produk berhasil diperbarui',
            'data' => $productUnit,
        ]);
    }

    public function destroy(Product $product, ProductUnit $productUnit)
    {
        $productUnit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Satuan produk berhasil dihapus',
        ]);
    }
}