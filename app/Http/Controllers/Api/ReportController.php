<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
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
        ]);

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
                    ->orWhere('barcode', 'like', "%{$search}%");
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
        | Filter Status
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
        | Summary
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

        return response()->json([
            'success' => true,
            'message' => 'Laporan stok berhasil diambil',

            'data' => [
                'filters' => [
                    'status' => $status,
                    'search' => $validated['search'] ?? null,
                ],

                'summary' => $summary,

                'products' => $products->values(),
            ],
        ]);
    }
}