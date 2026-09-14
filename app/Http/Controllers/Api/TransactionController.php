<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        // Kasir hanya melihat transaksi miliknya
        if (auth()->user()->role === 'kasir') {
            $query->where('user_id', auth()->id());
        }

        // Search berdasarkan nomor transaksi
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where('transaction_number', 'like', "%{$search}%");
        }

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter tanggal
        if ($request->filled('date')) {
            $query->whereDate('transaction_date', $request->date);
        }

        $transactions = $query
            ->latest('transaction_date')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Data transaksi berhasil diambil',
            'data' => $transactions,
        ]);
    }

    public function show(Transaction $transaction)
    {
        // Kasir hanya boleh melihat transaksi miliknya
        if (
            auth()->user()->role === 'kasir' &&
            $transaction->user_id !== auth()->id()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Kamu tidak memiliki izin untuk melihat transaksi ini.',
            ], 403);
        }

        $transaction->load([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail transaksi berhasil diambil',
            'data' => $transaction,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',

            'discount' => 'nullable|numeric|min:0',

            'paid' => 'required|numeric|min:0',

            'items' => 'required|array|min:1',

            'items.*.product_id' => 'required|exists:products,id',

            'items.*.unit_id' => 'required|exists:units,id',

            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);

        $transaction = DB::transaction(function () use ($validated) {

            $subtotal = 0;

            $items = [];

            foreach ($validated['items'] as $item) {

                $product = Product::lockForUpdate()
                    ->findOrFail($item['product_id']);

                $productUnit = ProductUnit::where('product_id', $product->id)
                    ->where('unit_id', $item['unit_id'])
                    ->where('is_active', true)
                    ->first();

                /*
                Jika satuan yang dipilih adalah
                satuan dasar produk, gunakan harga
                dari products.
                */
                if (!$productUnit) {

                    if ($product->base_unit_id != $item['unit_id']) {
                        abort(422, 'Satuan tidak tersedia untuk produk ' . $product->name);
                    }

                    $conversionRate = 1;
                    $unitPrice = $product->selling_price;

                } else {

                    $conversionRate = $productUnit->conversion_rate;
                    $unitPrice = $productUnit->selling_price;
                }

                /*
                Jumlah yang akan mengurangi stok.
                */
                $baseQuantity = $item['quantity'] * $conversionRate;

                if ($product->stock < $baseQuantity) {
                    abort(
                        422,
                        'Stok ' . $product->name . ' tidak mencukupi. ' .
                        'Stok tersedia: ' . $product->stock
                    );
                }

                $itemSubtotal = $item['quantity'] * $unitPrice;

                $subtotal += $itemSubtotal;

                $items[] = [
                    'product' => $product,
                    'unit_id' => $item['unit_id'],
                    'quantity' => $item['quantity'],
                    'conversion_rate' => $conversionRate,
                    'base_quantity' => $baseQuantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                ];
            }

            $discount = $validated['discount'] ?? 0;

            $total = max($subtotal - $discount, 0);

            if ($validated['paid'] < $total) {
                abort(422, 'Uang pembayaran kurang.');
            }

            $change = $validated['paid'] - $total;

            $transaction = Transaction::create([
                'transaction_number' => $this->generateTransactionNumber(),
                'customer_id' => $validated['customer_id'] ?? null,
                'user_id' => auth()->id(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => $validated['paid'],
                'change' => $change,
                'status' => 'completed',
                'transaction_date' => now(),
            ]);

            foreach ($items as $item) {

                $transaction->details()->create([
                    'product_id' => $item['product']->id,
                    'unit_id' => $item['unit_id'],
                    'quantity' => $item['quantity'],
                    'conversion_rate' => $item['conversion_rate'],
                    'base_quantity' => $item['base_quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                /*
                Kurangi stok produk.
                */
                $item['product']->decrement(
                    'stock',
                    $item['base_quantity']
                );

                /*
                Catat riwayat stok.
                */
                StockMovement::create([
                    'product_id' => $item['product']->id,
                    'user_id' => auth()->id() ?? 1,
                    'type' => 'out',
                    'quantity' => $item['base_quantity'],
                    'reference_type' => 'transaction',
                    'reference_id' => $transaction->id,
                    'note' => 'Penjualan ' . $transaction->transaction_number,
                ]);
            }

            return $transaction;
        });

        $transaction->load([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaksi berhasil dibuat',
            'data' => $transaction,
        ], 201);
    }

    private function generateTransactionNumber()
    {
        do {
            $number = 'TRX-' . now()->format('YmdHis') . '-' . rand(100, 999);
        } while (Transaction::where('transaction_number', $number)->exists());

        return $number;
    }

    public function reprint(Transaction $transaction)
    {
        // Kasir hanya boleh reprint transaksi miliknya
        if (
            auth()->user()->role === 'kasir' &&
            $transaction->user_id !== auth()->id()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Kamu tidak memiliki izin untuk mencetak transaksi ini.',
            ], 403);
        }

        $transaction->load([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data transaksi siap untuk dicetak',
            'data' => [
                'store' => [
                    'name' => 'BuildPOS',
                ],

                'transaction' => [
                    'id' => $transaction->id,
                    'transaction_number' => $transaction->transaction_number,
                    'transaction_date' => $transaction->transaction_date,
                    'status' => $transaction->status,
                ],

                'cashier' => [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                ],

                'customer' => $transaction->customer
                    ? [
                        'id' => $transaction->customer->id,
                        'name' => $transaction->customer->name,
                    ]
                    : [
                        'id' => null,
                        'name' => 'Pelanggan Umum',
                    ],

                'items' => $transaction->details->map(function ($detail) {
                    return [
                        'product' => $detail->product->name,
                        'quantity' => $detail->quantity,
                        'unit' => $detail->unit->name,
                        'unit_price' => $detail->unit_price,
                        'subtotal' => $detail->subtotal,
                    ];
                }),

                'summary' => [
                    'subtotal' => $transaction->subtotal,
                    'discount' => $transaction->discount,
                    'total' => $transaction->total,
                    'paid' => $transaction->paid,
                    'change' => $transaction->change,
                ],
            ],
        ]);
    }
}