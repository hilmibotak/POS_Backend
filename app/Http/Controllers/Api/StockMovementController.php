<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    /**
     * Menampilkan riwayat pergerakan stok
     */
    public function index()
    {
        $movements = StockMovement::with([
            'product',
            'user',
        ])
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat stok berhasil diambil',
            'data' => $movements,
        ]);
    }

    /**
     * Menampilkan detail pergerakan stok
     */
    public function show(StockMovement $stockMovement)
    {
        $stockMovement->load([
            'product',
            'user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail pergerakan stok berhasil diambil',
            'data' => $stockMovement,
        ]);
    }

    /**
     * Stok masuk
     */
    public function stockIn(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.001',
            'note' => 'nullable|string',
        ]);

        $movement = DB::transaction(function () use ($validated) {

            $product = Product::lockForUpdate()
                ->findOrFail($validated['product_id']);

            // Tambahkan stok
            $product->increment(
                'stock',
                $validated['quantity']
            );

            // Catat pergerakan stok
            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => 'in',
                'quantity' => $validated['quantity'],
                'reference_type' => 'stock_in',
                'reference_id' => null,
                'note' => $validated['note'] ?? 'Stok masuk',
            ]);
        });

        $movement->load([
            'product',
            'user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stok berhasil ditambahkan',
            'data' => $movement,
        ], 201);
    }

    /**
     * Penyesuaian stok
     */
    public function adjustment(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'note' => 'required|string',
        ]);

        $movement = DB::transaction(function () use ($validated) {

            $product = Product::lockForUpdate()
                ->findOrFail($validated['product_id']);

            $oldStock = $product->stock;
            $newStock = $validated['quantity'];

            if ($newStock < 0) {
                abort(422, 'Stok tidak boleh kurang dari 0.');
            }

            $difference = $newStock - $oldStock;

            // Set stok ke nilai baru
            $product->update([
                'stock' => $newStock,
            ]);

            // Catat selisih stok
            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => 'adjustment',
                'quantity' => $difference,
                'reference_type' => 'stock_adjustment',
                'reference_id' => null,
                'note' => $validated['note'],
            ]);
        });

        $movement->load([
            'product',
            'user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stok berhasil disesuaikan',
            'data' => $movement,
        ], 201);
    }
}