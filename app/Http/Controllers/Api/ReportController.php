<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Laporan Penjualan
    |--------------------------------------------------------------------------
    */

    public function sales(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:completed,cancelled'],
        ]);

        $startDate = $validated['start_date']
            ?? now()->startOfMonth()->toDateString();

        $endDate = $validated['end_date']
            ?? now()->toDateString();

        $query = Transaction::with([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ])
            ->whereBetween('transaction_date', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59',
            ]);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $transactions = $query
            ->latest('transaction_date')
            ->get();

        $summary = [
            'total_transactions' => $transactions->count(),
            'total_subtotal' => (float) $transactions->sum('subtotal'),
            'total_discount' => (float) $transactions->sum('discount'),
            'total_sales' => (float) $transactions->sum('total'),
            'total_paid' => (float) $transactions->sum('paid'),
            'total_change' => (float) $transactions->sum('change'),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Laporan penjualan berhasil diambil',
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'summary' => $summary,
                'transactions' => $transactions,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Laporan Stok
    |--------------------------------------------------------------------------
    |
    | Laporan ini berisi:
    | 1. Kondisi stok saat ini
    | 2. Riwayat barang masuk
    | 3. Riwayat barang keluar
    |
    */

    public function stock(Request $request)
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                'in:all,aman,menipis,habis',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'movement_start_date' => [
                'nullable',
                'date',
            ],

            'movement_end_date' => [
                'nullable',
                'date',
                'after_or_equal:movement_start_date',
            ],

            'movement_type' => [
                'nullable',
                'in:all,in,out',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | DATA STOK SAAT INI
        |--------------------------------------------------------------------------
        */

        $query = Product::with([
            'category',
            'baseUnit',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Search Produk
        |--------------------------------------------------------------------------
        */

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('size', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($categoryQuery) use ($search) {
                        $categoryQuery->where(
                            'name',
                            'like',
                            "%{$search}%"
                        );
                    });
            });
        }

        $products = $query
            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Tentukan Status Stok
        |--------------------------------------------------------------------------
        */

        $products = $products->map(function ($product) {
            $stock = (float) $product->stock;
            $minimumStock = (float) $product->minimum_stock;

            if ($stock <= 0) {
                $stockStatus = 'habis';
            } elseif ($stock <= $minimumStock) {
                $stockStatus = 'menipis';
            } else {
                $stockStatus = 'aman';
            }

            return [
                'id' => $product->id,

                'name' => $product->name,

                'brand' => $product->brand,

                'size' => $product->size,

                'barcode' => $product->barcode,

                'category' => $product->category,

                'base_unit' => $product->baseUnit,

                'purchase_price' => (float) $product->purchase_price,

                'selling_price' => (float) $product->selling_price,

                'stock' => $stock,

                'minimum_stock' => $minimumStock,

                'stock_status' => $stockStatus,

                'inventory_value' =>
                    $stock * (float) $product->purchase_price,

                'selling_value' =>
                    $stock * (float) $product->selling_price,

                'is_active' => $product->is_active,
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | Filter Status Stok
        |--------------------------------------------------------------------------
        */

        $status = $validated['status'] ?? 'all';

        if ($status !== 'all') {
            $products = $products
                ->filter(function ($product) use ($status) {
                    return $product['stock_status'] === $status;
                })
                ->values();
        }

        /*
        |--------------------------------------------------------------------------
        | Summary Stok
        |--------------------------------------------------------------------------
        */

        $summary = [
            'total_products' => $products->count(),

            'safe_products' => $products
                ->where('stock_status', 'aman')
                ->count(),

            'low_stock_products' => $products
                ->where('stock_status', 'menipis')
                ->count(),

            'out_of_stock_products' => $products
                ->where('stock_status', 'habis')
                ->count(),

            'total_inventory_value' => (float) $products
                ->sum('inventory_value'),

            'total_selling_value' => (float) $products
                ->sum('selling_value'),
        ];

        /*
        |--------------------------------------------------------------------------
        | RIWAYAT PERGERAKAN STOK
        |--------------------------------------------------------------------------
        */

        $movementQuery = StockMovement::with([
            'product.category',
            'product.baseUnit',
            'user',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Filter Tanggal Movement
        |--------------------------------------------------------------------------
        */

        $movementStartDate =
            $validated['movement_start_date']
            ?? now()->startOfMonth()->toDateString();

        $movementEndDate =
            $validated['movement_end_date']
            ?? now()->toDateString();

        $movementQuery->whereBetween('created_at', [
            $movementStartDate . ' 00:00:00',
            $movementEndDate . ' 23:59:59',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Filter Jenis Movement
        |--------------------------------------------------------------------------
        */

        $movementType =
            $validated['movement_type'] ?? 'all';

        if ($movementType !== 'all') {
            $movementQuery->where(
                'type',
                $movementType
            );
        } else {
            $movementQuery->whereIn('type', [
                'in',
                'out',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Search Movement
        |--------------------------------------------------------------------------
        */

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $movementQuery->where(function ($q) use ($search) {
                $q->where('note', 'like', "%{$search}%")
                    ->orWhere('reference_type', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search) {
                        $productQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('size', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil Movement
        |--------------------------------------------------------------------------
        */

        $movements = $movementQuery
            ->latest('created_at')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Format Movement
        |--------------------------------------------------------------------------
        */

        $movements = $movements->map(function ($movement) {
            $product = $movement->product;

            $unit = $product?->baseUnit;

            $quantity = (float) $movement->quantity;

            return [
                'id' => $movement->id,

                'date' => $movement->created_at,

                'type' => $movement->type,

                'type_label' => match ($movement->type) {
                    'in' => 'Barang Masuk',
                    'out' => 'Barang Keluar',
                    default => ucfirst($movement->type),
                },

                'quantity' => $quantity,

                'reference_type' => $movement->reference_type,

                'reference_id' => $movement->reference_id,

                'note' => $movement->note,

                'product' => [
                    'id' => $product?->id,

                    'name' => $product?->name,

                    'brand' => $product?->brand,

                    'size' => $product?->size,

                    'barcode' => $product?->barcode,

                    'category' => $product?->category,

                    'base_unit' => $unit,
                ],

                'user' => [
                    'id' => $movement->user?->id,

                    'name' => $movement->user?->name,
                ],
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | Summary Movement
        |--------------------------------------------------------------------------
        */

        $movementSummary = [
            'total_movements' => $movements->count(),

            'total_in_movements' => $movements
                ->where('type', 'in')
                ->count(),

            'total_out_movements' => $movements
                ->where('type', 'out')
                ->count(),

            'total_in_quantity' => (float) $movements
                ->where('type', 'in')
                ->sum('quantity'),

            'total_out_quantity' => (float) $movements
                ->where('type', 'out')
                ->sum('quantity'),
        ];

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,

            'message' => 'Laporan stok berhasil diambil',

            'data' => [
                /*
                |----------------------------------------------------------------------
                | Filter Stok
                |----------------------------------------------------------------------
                */

                'filters' => [
                    'status' => $status,

                    'search' =>
                        $validated['search'] ?? null,
                ],

                /*
                |----------------------------------------------------------------------
                | Summary Stok
                |----------------------------------------------------------------------
                */

                'summary' => $summary,

                /*
                |----------------------------------------------------------------------
                | Produk
                |----------------------------------------------------------------------
                */

                'products' => $products->values(),

                /*
                |----------------------------------------------------------------------
                | Filter Movement
                |----------------------------------------------------------------------
                */

                'movement_filters' => [
                    'start_date' => $movementStartDate,

                    'end_date' => $movementEndDate,

                    'type' => $movementType,

                    'search' =>
                        $validated['search'] ?? null,
                ],

                /*
                |----------------------------------------------------------------------
                | Summary Movement
                |----------------------------------------------------------------------
                */

                'movement_summary' => $movementSummary,

                /*
                |----------------------------------------------------------------------
                | Riwayat Movement
                |----------------------------------------------------------------------
                */

                'movements' => $movements->values(),
            ],
        ]);
    }
}