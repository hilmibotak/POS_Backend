<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUnit;
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
            'unit',
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
            'unit',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail pergerakan stok berhasil diambil',
            'data' => $stockMovement,
        ]);
    }

    /**
     * Stok masuk
     *
     * quantity = jumlah berdasarkan satuan yang dipilih
     * conversion_rate = konversi ke satuan dasar
     * base_quantity = jumlah yang benar-benar ditambahkan ke stok produk
     */
    public function stockIn(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'unit_id' => 'required|exists:units,id',
            'quantity' => 'required|numeric|min:0.001',
            'note' => 'nullable|string',
        ]);

        $movement = DB::transaction(function () use ($validated) {

            $product = Product::lockForUpdate()
                ->findOrFail($validated['product_id']);

            /*
             * Cari satuan yang digunakan untuk produk.
             *
             * Kalau unit yang dipilih adalah satuan dasar,
             * conversion_rate = 1.
             *
             * Kalau unit adalah satuan tambahan seperti Kolbak,
             * ambil conversion_rate dari product_units.
             */
            if ((int) $validated['unit_id'] === (int) $product->base_unit_id) {

                $conversionRate = 1;

            } else {

                $productUnit = ProductUnit::where('product_id', $product->id)
                    ->where('unit_id', $validated['unit_id'])
                    ->where('is_active', true)
                    ->first();

                if (!$productUnit) {
                    abort(
                        422,
                        'Satuan yang dipilih belum dikonfigurasi untuk produk ini.'
                    );
                }

                $conversionRate = (float) $productUnit->conversion_rate;

                if ($conversionRate <= 0) {
                    abort(
                        422,
                        'Konversi satuan produk harus lebih dari 0.'
                    );
                }
            }

            /*
             * Hitung jumlah dalam satuan dasar.
             *
             * Contoh:
             * 10 Kolbak × 500 = 5.000 Buah
             */
            $baseQuantity =
                (float) $validated['quantity'] * $conversionRate;

            /*
             * Tambahkan stok menggunakan satuan dasar.
             */
            $product->increment(
                'stock',
                $baseQuantity
            );

            /*
             * Simpan riwayat stok.
             *
             * quantity       = jumlah yang dimasukkan user
             * unit_id        = satuan yang dipilih
             * conversion_rate = nilai konversi
             * base_quantity  = jumlah yang masuk ke stok dasar
             */
            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => 'in',

                'quantity' => $validated['quantity'],
                'unit_id' => $validated['unit_id'],
                'conversion_rate' => $conversionRate,
                'base_quantity' => $baseQuantity,

                'reference_type' => 'stock_in',
                'reference_id' => null,

                'note' => $validated['note'] ?? 'Stok masuk',
            ]);
        });

        $movement->load([
            'product',
            'user',
            'unit',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stok berhasil ditambahkan',
            'data' => $movement,
        ], 201);
    }

    /**
     * Penyesuaian stok
     *
     * Adjustment tetap menggunakan satuan dasar produk.
     */
    public function adjustment(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0',
            'note' => 'required|string',
        ]);

        $movement = DB::transaction(function () use ($validated) {

            $product = Product::lockForUpdate()
                ->findOrFail($validated['product_id']);

            $oldStock = (float) $product->stock;
            $newStock = (float) $validated['quantity'];

            $difference = $newStock - $oldStock;

            /*
             * Set stok ke nilai baru.
             */
            $product->update([
                'stock' => $newStock,
            ]);

            /*
             * Adjustment dicatat dalam satuan dasar.
             */
            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => 'adjustment',

                'quantity' => $difference,
                'unit_id' => $product->base_unit_id,
                'conversion_rate' => 1,
                'base_quantity' => $difference,

                'reference_type' => 'stock_adjustment',
                'reference_id' => null,

                'note' => $validated['note'],
            ]);
        });

        $movement->load([
            'product',
            'user',
            'unit',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stok berhasil disesuaikan',
            'data' => $movement,
        ], 201);
    }
}