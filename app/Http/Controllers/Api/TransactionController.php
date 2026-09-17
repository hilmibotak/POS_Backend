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
            $query->where(
                'user_id',
                auth()->id()
            );
        }

        // Search nomor transaksi
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(
                'transaction_number',
                'like',
                "%{$search}%"
            );
        }

        // Filter status transaksi
        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        // Filter status pembayaran
        if ($request->filled('payment_status')) {
            $query->where(
                'payment_status',
                $request->payment_status
            );
        }

        // Filter metode pembayaran
        if ($request->filled('payment_method')) {
            $query->where(
                'payment_method',
                $request->payment_method
            );
        }

        // Filter tanggal
        if ($request->filled('date')) {
            $query->whereDate(
                'transaction_date',
                $request->date
            );
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
                'message' =>
                    'Kamu tidak memiliki izin untuk melihat transaksi ini.',
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
            'message' =>
                'Detail transaksi berhasil diambil',
            'data' => $transaction,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' =>
                'nullable|exists:customers,id',

            'discount' =>
                'nullable|numeric|min:0',

            'paid' =>
                'required|numeric|min:0',

            'payment_method' => [
                'required',
                'in:cash,qris,transfer,debit,credit,bon',
            ],

            'items' =>
                'required|array|min:1',

            'items.*.product_id' =>
                'required|exists:products,id',

            'items.*.unit_id' =>
                'required|exists:units,id',

            'items.*.quantity' =>
                'required|numeric|min:0.001',
        ]);

        $paymentMethod = $validated['payment_method'];

        $transaction = DB::transaction(
            function () use (
                $validated,
                $paymentMethod
            ) {
                $subtotal = 0;

                $items = [];

                /*
                =====================================================
                1. CEK PRODUK, SATUAN, HARGA DAN STOK
                =====================================================
                */

                foreach ($validated['items'] as $item) {

                    $product = Product::lockForUpdate()
                        ->findOrFail(
                            $item['product_id']
                        );

                    $productUnit = ProductUnit::where(
                        'product_id',
                        $product->id
                    )
                        ->where(
                            'unit_id',
                            $item['unit_id']
                        )
                        ->where(
                            'is_active',
                            true
                        )
                        ->first();

                    /*
                    Jika satuan yang dipilih adalah
                    satuan dasar produk.
                    */
                    if (!$productUnit) {

                        if (
                            $product->base_unit_id !=
                            $item['unit_id']
                        ) {
                            abort(
                                422,
                                'Satuan tidak tersedia untuk produk ' .
                                $product->name
                            );
                        }

                        $conversionRate = 1;

                        $unitPrice =
                            $product->selling_price;

                    } else {

                        $conversionRate =
                            $productUnit->conversion_rate;

                        $unitPrice =
                            $productUnit->selling_price;
                    }

                    /*
                    Jumlah dalam satuan dasar.
                    */
                    $baseQuantity =
                        $item['quantity'] *
                        $conversionRate;

                    /*
                    Subtotal item.
                    */
                    $itemSubtotal =
                        $item['quantity'] *
                        $unitPrice;

                    $subtotal += $itemSubtotal;

                    $items[] = [
                        'product' => $product,
                        'unit_id' => $item['unit_id'],
                        'quantity' => $item['quantity'],
                        'conversion_rate' =>
                            $conversionRate,
                        'base_quantity' =>
                            $baseQuantity,
                        'unit_price' =>
                            $unitPrice,
                        'subtotal' =>
                            $itemSubtotal,
                    ];
                }

                /*
                =====================================================
                2. HITUNG TOTAL
                =====================================================
                */

                $discount =
                    $validated['discount'] ?? 0;

                $total = max(
                    $subtotal - $discount,
                    0
                );

                /*
                =====================================================
                3. VALIDASI PEMBAYARAN
                =====================================================

                Cash/debit/credit harus membayar penuh.

                QRIS/transfer boleh masuk pending.
                Bon juga tidak harus dibayar sekarang.
                */

                if (
                    in_array(
                        $paymentMethod,
                        ['cash', 'debit', 'credit']
                    ) &&
                    $validated['paid'] < $total
                ) {
                    abort(
                        422,
                        'Uang pembayaran kurang.'
                    );
                }

                /*
                Untuk QRIS/transfer/bon,
                paid boleh 0 karena belum diterima.
                */

                $paid = $validated['paid'];

                /*
                =====================================================
                4. HITUNG STATUS PEMBAYARAN
                =====================================================
                */

                if (
                    in_array(
                        $paymentMethod,
                        ['qris', 'transfer', 'bon']
                    )
                ) {
                    $paymentStatus = 'pending';
                } else {
                    $paymentStatus = 'completed';
                }

                /*
                =====================================================
                5. STATUS TRANSAKSI
                =====================================================
                */

                $transactionStatus = 'completed';

                /*
                =====================================================
                6. HITUNG KEMBALIAN
                =====================================================
                */

                $change = max(
                    $paid - $total,
                    0
                );

                /*
                =====================================================
                7. BUAT TRANSAKSI
                =====================================================
                */

                $transaction =
                    Transaction::create([
                        'transaction_number' =>
                            $this->generateTransactionNumber(),

                        'customer_id' =>
                            $validated['customer_id'] ??
                            null,

                        'user_id' =>
                            auth()->id(),

                        'subtotal' =>
                            $subtotal,

                        'discount' =>
                            $discount,

                        'total' =>
                            $total,

                        'paid' =>
                            $paid,

                        'change' =>
                            $change,

                        'payment_method' =>
                            $paymentMethod,

                        'payment_status' =>
                            $paymentStatus,

                        'status' =>
                            $transactionStatus,

                        'transaction_date' =>
                            now(),
                    ]);

                /*
                =====================================================
                8. SIMPAN DETAIL TRANSAKSI
                =====================================================
                */

                foreach ($items as $item) {

                    $transaction->details()->create([
                        'product_id' =>
                            $item['product']->id,

                        'unit_id' =>
                            $item['unit_id'],

                        'quantity' =>
                            $item['quantity'],

                        'conversion_rate' =>
                            $item['conversion_rate'],

                        'base_quantity' =>
                            $item['base_quantity'],

                        'unit_price' =>
                            $item['unit_price'],

                        'subtotal' =>
                            $item['subtotal'],
                    ]);
                }

                /*
                =====================================================
                9. KURANGI STOK
                =====================================================

                Cash/debit/credit:
                    langsung kurangi stok.

                Bon:
                    barang dibawa sekarang,
                    jadi stok langsung berkurang.

                QRIS/transfer:
                    belum kurangi stok karena pembayaran
                    masih menunggu konfirmasi admin.
                */

                $shouldDecreaseStock =
                    in_array(
                        $paymentMethod,
                        [
                            'cash',
                            'debit',
                            'credit',
                            'bon',
                        ]
                    );

                if ($shouldDecreaseStock) {

                    foreach ($items as $item) {

                        if (
                            $item['product']->stock <
                            $item['base_quantity']
                        ) {
                            abort(
                                422,
                                'Stok ' .
                                $item['product']->name .
                                ' tidak mencukupi. ' .
                                'Stok tersedia: ' .
                                $item['product']->stock
                            );
                        }

                        /*
                        Kurangi stok.
                        */
                        $item['product']->decrement(
                            'stock',
                            $item['base_quantity']
                        );

                        /*
                        Catat pergerakan stok.
                        */
                        StockMovement::create([
                            'product_id' =>
                                $item['product']->id,

                            'user_id' =>
                                auth()->id() ?? 1,

                            'type' =>
                                'out',

                            'quantity' =>
                                $item['base_quantity'],

                            'reference_type' =>
                                'transaction',

                            'reference_id' =>
                                $transaction->id,

                            'note' =>
                                'Penjualan ' .
                                $transaction->transaction_number,
                        ]);
                    }
                }

                return $transaction;
            }
        );

        $transaction->load([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                $transaction->payment_status === 'pending'
                    ? 'Transaksi berhasil dibuat dan menunggu konfirmasi pembayaran.'
                    : 'Transaksi berhasil dibuat',

            'data' => $transaction,
        ], 201);
    }

    /*
    =========================================================
    KONFIRMASI PEMBAYARAN
    =========================================================

    Digunakan Admin untuk QRIS / Transfer.

    Setelah dikonfirmasi:
    - payment_status = completed
    - stok dikurangi
    - stock movement dibuat
    */

    public function confirmPayment(Transaction $transaction)
    {
        if (
            !in_array(
                $transaction->payment_method,
                ['qris', 'transfer']
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Transaksi ini tidak memerlukan konfirmasi pembayaran.',
            ], 422);
        }

        if (
            $transaction->payment_status ===
            'completed'
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Pembayaran transaksi ini sudah dikonfirmasi.',
            ], 422);
        }

        DB::transaction(function () use ($transaction) {

            $transaction = Transaction::lockForUpdate()
                ->findOrFail($transaction->id);

            $transaction->load([
                'details.product',
            ]);

            foreach ($transaction->details as $detail) {

                $product = Product::lockForUpdate()
                    ->findOrFail(
                        $detail->product_id
                    );

                if (
                    $product->stock <
                    $detail->base_quantity
                ) {
                    abort(
                        422,
                        'Stok ' .
                        $product->name .
                        ' tidak mencukupi saat pembayaran dikonfirmasi. ' .
                        'Stok tersedia: ' .
                        $product->stock
                    );
                }

                /*
                Kurangi stok setelah pembayaran
                benar-benar dikonfirmasi.
                */
                $product->decrement(
                    'stock',
                    $detail->base_quantity
                );

                /*
                Catat stok keluar.
                */
                StockMovement::create([
                    'product_id' =>
                        $product->id,

                    'user_id' =>
                        auth()->id(),

                    'type' =>
                        'out',

                    'quantity' =>
                        $detail->base_quantity,

                    'reference_type' =>
                        'transaction',

                    'reference_id' =>
                        $transaction->id,

                    'note' =>
                        'Pembayaran dikonfirmasi - ' .
                        $transaction->transaction_number,
                ]);
            }

            /*
            Tandai pembayaran selesai.
            */
            $transaction->update([
                'payment_status' =>
                    'completed',

                'paid' =>
                    $transaction->total,

                'change' =>
                    0,
            ]);
        });

        $transaction->load([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Pembayaran berhasil dikonfirmasi dan stok telah dikurangi.',
            'data' => $transaction,
        ]);
    }

    private function generateTransactionNumber()
    {
        do {
            $number =
                'TRX-' .
                now()->format('YmdHis') .
                '-' .
                rand(100, 999);

        } while (
            Transaction::where(
                'transaction_number',
                $number
            )->exists()
        );

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
                'message' =>
                    'Kamu tidak memiliki izin untuk mencetak transaksi ini.',
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
            'message' =>
                'Data transaksi siap untuk dicetak',

            'data' => [

                'store' => [
                    'name' => 'BuildPOS',
                ],

                'transaction' => [
                    'id' =>
                        $transaction->id,

                    'transaction_number' =>
                        $transaction->transaction_number,

                    'transaction_date' =>
                        $transaction->transaction_date,

                    'status' =>
                        $transaction->status,

                    'payment_method' =>
                        $transaction->payment_method,

                    'payment_status' =>
                        $transaction->payment_status,
                ],

                'cashier' => [
                    'id' =>
                        $transaction->user->id,

                    'name' =>
                        $transaction->user->name,
                ],

                'customer' =>
                    $transaction->customer
                        ? [
                            'id' =>
                                $transaction->customer->id,

                            'name' =>
                                $transaction->customer->name,
                        ]
                        : [
                            'id' => null,
                            'name' =>
                                'Pelanggan Umum',
                        ],

                'items' =>
                    $transaction->details->map(
                        function ($detail) {

                            return [

                                'product' => [
                                    'id' =>
                                        $detail->product->id,

                                    'name' =>
                                        $detail->product->name,

                                    'brand' =>
                                        $detail->product->brand,

                                    'size' =>
                                        $detail->product->size,
                                ],

                                'quantity' =>
                                    $detail->quantity,

                                'unit' =>
                                    $detail->unit->name,

                                'unit_symbol' =>
                                    $detail->unit->symbol,

                                'conversion_rate' =>
                                    $detail->conversion_rate,

                                'base_quantity' =>
                                    $detail->base_quantity,

                                'unit_price' =>
                                    $detail->unit_price,

                                'subtotal' =>
                                    $detail->subtotal,
                            ];
                        }
                    )->values(),

                'summary' => [
                    'subtotal' =>
                        $transaction->subtotal,

                    'discount' =>
                        $transaction->discount,

                    'total' =>
                        $transaction->total,

                    'paid' =>
                        $transaction->paid,

                    'change' =>
                        $transaction->change,
                ],
            ],
        ]);
    }
}