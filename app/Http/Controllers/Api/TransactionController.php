<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentHistory;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class TransactionController extends Controller
{
    /**
     * Menampilkan daftar transaksi.
     */
    public function index(Request $request)
    {
        $query = Transaction::with([
            'customer',
            'user',
            'details.product',
            'details.unit',
        ]);

        // Kasir hanya bisa melihat transaksi miliknya sendiri
        if (auth()->user()->role === 'kasir') {
            $query->where('user_id', auth()->id());
        }

        // Search nomor transaksi
        if ($request->filled('search')) {
            $query->where(
                'transaction_number',
                'like',
                '%' . $request->search . '%'
            );
        }

        // Filter status transaksi
        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
            'message' => 'Riwayat transaksi berhasil diambil',
            'data' => $transactions,
        ]);
    }

    /**
     * Menampilkan detail transaksi.
     */
    public function show(Transaction $transaction)
    {
        // Kasir hanya bisa melihat transaksi miliknya
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
            'paymentHistories.user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail transaksi berhasil diambil',
            'data' => $transaction,
        ]);
    }

    /**
     * Membuat transaksi baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => [
                'nullable',
                'exists:customers,id',
            ],

            'discount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'paid' => [
                'required',
                'numeric',
                'min:0',
            ],

            'payment_method' => [
                'required',
                'in:cash,qris,transfer,debit,credit,bon,partial',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.product_id' => [
                'required',
                'exists:products,id',
            ],

            'items.*.unit_id' => [
                'required',
                'exists:units,id',
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'min:0.001',
            ],
        ]);

        $paymentMethod = $validated['payment_method'];

        // BON dan CREDIT harus memiliki customer
        if (
            in_array($paymentMethod, ['bon', 'credit']) &&
            empty($validated['customer_id'])
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Customer wajib dipilih untuk transaksi BON atau CREDIT.',
            ], 422);
        }

        // PARTIAL juga harus memiliki customer
        if (
            $paymentMethod === 'partial' &&
            empty($validated['customer_id'])
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Customer wajib dipilih untuk pembayaran partial.',
            ], 422);
        }

        try {
            $transaction = DB::transaction(function () use (
                $validated,
                $paymentMethod
            ) {
                $subtotal = 0;

                $transactionItems = [];

                /*
                |--------------------------------------------------------------------------
                | Hitung setiap item
                |--------------------------------------------------------------------------
                */

                foreach ($validated['items'] as $item) {
                    $product = Product::lockForUpdate()
                        ->findOrFail($item['product_id']);

                    /*
                    |--------------------------------------------------------------------------
                    | Tentukan conversion rate dan harga
                    |--------------------------------------------------------------------------
                    */

                    if (
                        (int) $item['unit_id'] ===
                        (int) $product->base_unit_id
                    ) {
                        // Jika menggunakan satuan dasar
                        $conversionRate = 1;

                        $unitPrice = (float) $product->selling_price;
                    } else {
                        // Jika menggunakan satuan alternatif
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

                        if (!$productUnit) {
                            abort(
                                422,
                                'Satuan yang dipilih belum dikonfigurasi untuk produk "' .
                                $product->name .
                                '".'
                            );
                        }

                        $conversionRate =
                            (float) $productUnit->conversion_rate;

                        $unitPrice =
                            (float) $productUnit->selling_price;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Hitung stok dalam base unit
                    |--------------------------------------------------------------------------
                    */

                    $quantity =
                        (float) $item['quantity'];

                    $baseQuantity =
                        $quantity * $conversionRate;

                    /*
                    |--------------------------------------------------------------------------
                    | Cek stok
                    |--------------------------------------------------------------------------
                    */

                    if ($product->stock < $baseQuantity) {
                        abort(
                            422,
                            'Stok produk "' .
                            $product->name .
                            '" tidak mencukupi.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Hitung subtotal item
                    |--------------------------------------------------------------------------
                    */

                    $itemSubtotal =
                        $quantity * $unitPrice;

                    $subtotal += $itemSubtotal;

                    $transactionItems[] = [
                        'product' => $product,
                        'product_id' => $product->id,
                        'unit_id' => $item['unit_id'],
                        'quantity' => $quantity,
                        'conversion_rate' => $conversionRate,
                        'base_quantity' => $baseQuantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $itemSubtotal,
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Hitung total
                |--------------------------------------------------------------------------
                */

                $discount =
                    (float) ($validated['discount'] ?? 0);

                $total =
                    max(
                        $subtotal - $discount,
                        0
                    );

                $paid =
                    (float) $validated['paid'];

                /*
                |--------------------------------------------------------------------------
                | Aturan pembayaran
                |--------------------------------------------------------------------------
                */

                // BON dan CREDIT = bayar nanti
                if (
                    in_array(
                        $paymentMethod,
                        ['bon', 'credit']
                    )
                ) {
                    $paid = 0;
                }

                // PARTIAL = harus bayar sebagian
                if ($paymentMethod === 'partial') {
                    if ($paid <= 0) {
                        abort(
                            422,
                            'Pembayaran partial harus lebih dari 0.'
                        );
                    }

                    if ($paid >= $total) {
                        abort(
                            422,
                            'Pembayaran partial harus kurang dari total transaksi.'
                        );
                    }
                }

                // Cash dan debit harus lunas
                if (
                    in_array(
                        $paymentMethod,
                        ['cash', 'debit']
                    ) &&
                    $paid < $total
                ) {
                    abort(
                        422,
                        'Uang pembayaran kurang.'
                    );
                }

                // QRIS dan transfer menunggu konfirmasi admin
                if (
                    in_array(
                        $paymentMethod,
                        ['qris', 'transfer']
                    )
                ) {
                    $paid = 0;
                }

                /*
                |--------------------------------------------------------------------------
                | Tentukan status pembayaran
                |--------------------------------------------------------------------------
                */

                if (
                    in_array(
                        $paymentMethod,
                        ['bon', 'credit']
                    )
                ) {
                    $paymentStatus = 'unpaid';
                } elseif ($paymentMethod === 'partial') {
                    $paymentStatus = 'partial';
                } elseif (
                    in_array(
                        $paymentMethod,
                        ['qris', 'transfer']
                    )
                ) {
                    $paymentStatus = 'pending';
                } else {
                    $paymentStatus = 'completed';
                }

                /*
                |--------------------------------------------------------------------------
                | Hitung kembalian dan sisa tagihan
                |--------------------------------------------------------------------------
                */

                $change =
                    max(
                        $paid - $total,
                        0
                    );

                $remainingAmount =
                    max(
                        $total - $paid,
                        0
                    );

                /*
                |--------------------------------------------------------------------------
                | Buat nomor transaksi
                |--------------------------------------------------------------------------
                */

                $transactionNumber =
                    $this->generateTransactionNumber();

                /*
                |--------------------------------------------------------------------------
                | Buat transaksi
                |--------------------------------------------------------------------------
                */

                $transaction = Transaction::create([
                    'transaction_number' =>
                        $transactionNumber,

                    'customer_id' =>
                        $validated['customer_id'] ?? null,

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

                    'remaining_amount' =>
                        $remainingAmount,

                    'payment_method' =>
                        $paymentMethod,

                    'payment_status' =>
                        $paymentStatus,

                    'status' =>
                        'completed',

                    'transaction_date' =>
                        now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Simpan detail transaksi
                |--------------------------------------------------------------------------
                */

                foreach ($transactionItems as $item) {
                    $transaction->details()->create([
                        'product_id' =>
                            $item['product_id'],

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
                |--------------------------------------------------------------------------
                | Simpan pembayaran awal ke payment_histories
                |--------------------------------------------------------------------------
                |
                | Hanya jika memang ada uang yang dibayarkan.
                | BON / CREDIT = 0
                | QRIS / TRANSFER = 0 sampai dikonfirmasi admin.
                |
                */

                if ($paid > 0) {
                    PaymentHistory::create([
                        'transaction_id' =>
                            $transaction->id,

                        'user_id' =>
                            auth()->id(),

                        'payment_method' =>
                            $paymentMethod,

                        'amount' =>
                            $paid,

                        'payment_date' =>
                            now(),

                        'note' =>
                            $paymentMethod === 'partial'
                                ? 'Pembayaran awal transaksi'
                                : 'Pembayaran transaksi',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Kurangi stok
                |--------------------------------------------------------------------------
                |
                | Cash
                | Debit
                | BON
                | CREDIT
                | Partial
                |
                | langsung mengurangi stok.
                |
                | QRIS / Transfer masih pending sehingga stok
                | belum dikurangi sampai admin melakukan konfirmasi.
                |
                */

                if (
                    in_array(
                        $paymentMethod,
                        [
                            'cash',
                            'debit',
                            'credit',
                            'bon',
                            'partial',
                        ]
                    )
                ) {
                    foreach ($transactionItems as $item) {
                        $product =
                            $item['product'];

                        $product->decrement(
                            'stock',
                            $item['base_quantity']
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Catat stock movement
                        |--------------------------------------------------------------------------
                        */

                        StockMovement::create([
                            'product_id' =>
                                $product->id,

                            'user_id' =>
                                auth()->id(),

                            'type' =>
                                'out',

                            'quantity' =>
                                $item['quantity'],

                            'unit_id' =>
                                $item['unit_id'],

                            'conversion_rate' =>
                                $item['conversion_rate'],

                            'base_quantity' =>
                                $item['base_quantity'],

                            'reference_type' =>
                                'transaction',

                            'reference_id' =>
                                $transaction->id,

                            'note' =>
                                'Stok keluar dari transaksi ' .
                                $transaction->transaction_number,
                        ]);
                    }
                }

                return $transaction;
            });

            /*
            |--------------------------------------------------------------------------
            | Load data lengkap
            |--------------------------------------------------------------------------
            */

            $transaction->load([
                'customer',
                'user',
                'details.product',
                'details.unit',
                'paymentHistories.user',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Response message
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $transaction->payment_status,
                    ['unpaid', 'partial']
                )
            ) {
                $message =
                    'Transaksi berhasil dibuat dan masuk ke tagihan.';
            } elseif (
                $transaction->payment_status === 'pending'
            ) {
                $message =
                    'Transaksi berhasil dibuat dan menunggu konfirmasi pembayaran.';
            } else {
                $message =
                    'Transaksi berhasil dibuat.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $transaction,
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' =>
                    $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Konfirmasi pembayaran QRIS / Transfer oleh Admin.
     */
    /**
 * Konfirmasi pembayaran QRIS / Transfer oleh Admin.
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
                    'Transaksi ini tidak membutuhkan konfirmasi pembayaran.',
            ], 422);
        }

        if (
            $transaction->payment_status === 'completed'
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Pembayaran transaksi ini sudah dikonfirmasi.',
            ], 422);
        }

        try {
            $transaction = DB::transaction(function () use ($transaction) {

                /*
                |--------------------------------------------------------------------------
                | Lock transaksi
                |--------------------------------------------------------------------------
                */

                $transaction = Transaction::lockForUpdate()
                    ->findOrFail($transaction->id);

                /*
                |--------------------------------------------------------------------------
                | Pastikan masih pending
                |--------------------------------------------------------------------------
                */

                if (
                    $transaction->payment_status !== 'pending'
                ) {
                    abort(
                        422,
                        'Transaksi ini sudah tidak berstatus pending.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Load detail transaksi
                |--------------------------------------------------------------------------
                */

                $transaction->load([
                    'details.product',
                    'details.unit',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Lock semua produk dan cek stok
                |--------------------------------------------------------------------------
                |
                | Semua stok dicek terlebih dahulu.
                | Kalau ada satu saja yang tidak cukup,
                | tidak ada stok yang dikurangi.
                |
                */

                $products = [];

                foreach ($transaction->details as $detail) {

                    $product = Product::lockForUpdate()
                        ->findOrFail($detail->product_id);

                    if (
                        (float) $product->stock <
                        (float) $detail->base_quantity
                    ) {
                        abort(
                            422,
                            'Stok produk "' .
                            $product->name .
                            '" tidak mencukupi untuk konfirmasi transaksi. ' .
                            'Stok tersedia: ' .
                            $product->stock .
                            ', kebutuhan: ' .
                            $detail->base_quantity .
                            '.'
                        );
                    }

                    $products[$product->id] = $product;
                }

                /*
                |--------------------------------------------------------------------------
                | Semua stok cukup
                | Sekarang baru kurangi stok
                |--------------------------------------------------------------------------
                */

                foreach ($transaction->details as $detail) {

                    $product = $products[$detail->product_id];

                    $product->decrement(
                        'stock',
                        $detail->base_quantity
                    );

                    StockMovement::create([
                        'product_id' =>
                            $product->id,

                        'user_id' =>
                            auth()->id(),

                        'type' =>
                            'out',

                        'quantity' =>
                            $detail->quantity,

                        'unit_id' =>
                            $detail->unit_id,

                        'conversion_rate' =>
                            $detail->conversion_rate,

                        'base_quantity' =>
                            $detail->base_quantity,

                        'reference_type' =>
                            'transaction',

                        'reference_id' =>
                            $transaction->id,

                        'note' =>
                            'Stok keluar setelah konfirmasi pembayaran transaksi ' .
                            $transaction->transaction_number,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Update pembayaran
                |--------------------------------------------------------------------------
                */

                $transaction->update([
                    'paid' =>
                        $transaction->total,

                    'remaining_amount' =>
                        0,

                    'change' =>
                        0,

                    'payment_status' =>
                        'completed',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Simpan payment history
                |--------------------------------------------------------------------------
                */

                PaymentHistory::create([
                    'transaction_id' =>
                        $transaction->id,

                    'user_id' =>
                        auth()->id(),

                    'payment_method' =>
                        $transaction->payment_method,

                    'amount' =>
                        $transaction->total,

                    'payment_date' =>
                        now(),

                    'note' =>
                        'Pembayaran dikonfirmasi oleh admin',
                ]);

                return $transaction;
            });

            /*
            |--------------------------------------------------------------------------
            | Load data terbaru
            |--------------------------------------------------------------------------
            */

            $transaction->load([
                'customer',
                'user',
                'details.product',
                'details.unit',
                'paymentHistories.user',
            ]);

            return response()->json([
                'success' => true,
                'message' =>
                    'Pembayaran berhasil dikonfirmasi dan stok telah dikurangi.',
                'data' =>
                    $transaction,
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Membayar tagihan BON / CREDIT / PARTIAL.
     */
    public function payDebt(
        Request $request,
        Transaction $transaction
    ) {
        $validated = $request->validate([
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'payment_method' => [
                'required',
                'in:cash,transfer,qris,debit',
            ],

            'note' => [
                'nullable',
                'string',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Kasir hanya bisa membayar transaksi miliknya
        |--------------------------------------------------------------------------
        */

        if (
            auth()->user()->role === 'kasir' &&
            $transaction->user_id !== auth()->id()
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Kamu tidak memiliki izin untuk membayar transaksi ini.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Cek status transaksi
        |--------------------------------------------------------------------------
        */

        if (
            $transaction->payment_status ===
            'completed'
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Transaksi ini sudah lunas.',
            ], 422);
        }

        if (
            $transaction->remaining_amount <= 0
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Tidak ada sisa pembayaran.',
            ], 422);
        }

        try {
            $transaction = DB::transaction(
                function () use (
                    $validated,
                    $transaction
                ) {
                    /*
                    |--------------------------------------------------------------------------
                    | Lock transaksi
                    |--------------------------------------------------------------------------
                    */

                    $transaction =
                        Transaction::lockForUpdate()
                            ->findOrFail(
                                $transaction->id
                            );

                    $remaining =
                        (float) $transaction
                            ->remaining_amount;

                    $amount =
                        (float) $validated['amount'];

                    /*
                    |--------------------------------------------------------------------------
                    | Jangan boleh membayar lebih dari sisa tagihan
                    |--------------------------------------------------------------------------
                    */

                    if ($amount > $remaining) {
                        abort(
                            422,
                            'Nominal pembayaran melebihi sisa tagihan.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Hitung pembayaran baru
                    |--------------------------------------------------------------------------
                    */

                    $newPaid =
                        (float) $transaction->paid +
                        $amount;

                    $newRemaining =
                        $remaining -
                        $amount;

                    $newStatus =
                        $newRemaining <= 0
                            ? 'completed'
                            : 'partial';

                    /*
                    |--------------------------------------------------------------------------
                    | Simpan payment history
                    |--------------------------------------------------------------------------
                    */

                    PaymentHistory::create([
                        'transaction_id' =>
                            $transaction->id,

                        'user_id' =>
                            auth()->id(),

                        'payment_method' =>
                            $validated['payment_method'],

                        'amount' =>
                            $amount,

                        'payment_date' =>
                            now(),

                        'note' =>
                            $validated['note'] ??
                            'Pembayaran tagihan',
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Update transaksi
                    |--------------------------------------------------------------------------
                    */

                    $transaction->update([
                        'paid' =>
                            $newPaid,

                        'remaining_amount' =>
                            $newRemaining,

                        'payment_status' =>
                            $newStatus,

                        'change' =>
                            0,
                    ]);

                    return $transaction;
                }
            );

            /*
            |--------------------------------------------------------------------------
            | Load data terbaru
            |--------------------------------------------------------------------------
            */

            $transaction->load([
                'customer',
                'user',
                'details.product',
                'details.unit',
                'paymentHistories.user',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            if (
                $transaction->payment_status ===
                'completed'
            ) {
                $message =
                    'Pembayaran berhasil dan transaksi sudah lunas.';
            } else {
                $message =
                    'Pembayaran berhasil dicatat.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $transaction,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' =>
                    $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Menampilkan semua riwayat pembayaran.
     */
    public function paymentHistory(Request $request)
    {
        $query = PaymentHistory::with([
            'transaction.customer',
            'transaction.user',
            'user',
        ]);

        if (auth()->user()->role === 'kasir') {
            $query->whereHas('transaction', function ($q) {
                $q->where('user_id', auth()->id());
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->whereHas('transaction', function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('payment_method')) {
            $query->where(
                'payment_method',
                $request->payment_method
            );
        }

        if ($request->filled('date')) {
            $query->whereDate(
                'payment_date',
                $request->date
            );
        }

        $histories = $query
            ->latest('payment_date')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat pembayaran berhasil diambil',
            'data' => $histories,
        ]);
    }

    /**
     * Generate nomor transaksi.
     */
    private function generateTransactionNumber()
    {
        $prefix =
            'TRX-' .
            now()->format('Ymd') .
            '-';

        $lastTransaction =
            Transaction::where(
                'transaction_number',
                'like',
                $prefix . '%'
            )
                ->orderByDesc('id')
                ->first();

        if (!$lastTransaction) {
            $number = 1;
        } else {
            $lastNumber =
                (int) substr(
                    $lastTransaction
                        ->transaction_number,
                    -4
                );

            $number =
                $lastNumber + 1;
        }

        return $prefix .
            str_pad(
                $number,
                4,
                '0',
                STR_PAD_LEFT
            );
    }

    /**
     * Cetak / reprint transaksi.
     */
    public function reprint(Transaction $transaction)
    {
        /*
        |--------------------------------------------------------------------------
        | Kasir hanya bisa reprint transaksi miliknya
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Load data transaksi
        |--------------------------------------------------------------------------
        */

        $transaction->load([
            'customer',
            'user',
            'details.product',
            'details.unit',
            'paymentHistories.user',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Format data untuk struk
        |--------------------------------------------------------------------------
        */

        $data = [
            'store' => [
                'name' =>
                    'BuildPOS',

                'address' =>
                    'Toko Material Bangunan',

                'phone' =>
                    '-',
            ],

            'transaction' => [
                'id' =>
                    $transaction->id,

                'transaction_number' =>
                    $transaction->transaction_number,

                'date' =>
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
                    $transaction->user?->id,

                'name' =>
                    $transaction->user?->name,
            ],

            'customer' => [
                'id' =>
                    $transaction->customer?->id,

                'name' =>
                    $transaction->customer?->name,

                'phone' =>
                    $transaction->customer?->phone,
            ],

            'items' =>
                $transaction->details
                    ->map(
                        function ($detail) {
                            return [
                                'product_id' =>
                                    $detail->product_id,

                                'product_name' =>
                                    $detail->product?->name,

                                'unit' =>
                                    $detail->unit?->name,

                                'quantity' =>
                                    $detail->quantity,

                                'unit_price' =>
                                    $detail->unit_price,

                                'subtotal' =>
                                    $detail->subtotal,
                            ];
                        }
                    )
                    ->values(),

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

                'remaining_amount' =>
                    $transaction->remaining_amount,
            ],

            'payment_histories' =>
                $transaction
                    ->paymentHistories
                    ->map(
                        function ($payment) {
                            return [
                                'id' =>
                                    $payment->id,

                                'payment_method' =>
                                    $payment->payment_method,

                                'amount' =>
                                    $payment->amount,

                                'payment_date' =>
                                    $payment->payment_date,

                                'note' =>
                                    $payment->note,

                                'user' =>
                                    $payment->user?->name,
                            ];
                        }
                    )
                    ->values(),
        ];

        return response()->json([
            'success' => true,
            'message' =>
                'Data reprint transaksi berhasil diambil',
            'data' => $data,
        ]);
    }
}
