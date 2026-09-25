<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentHistory;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Setting;
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

        /*
        |--------------------------------------------------------------------------
        | Kasir hanya bisa melihat transaksi miliknya sendiri
        |--------------------------------------------------------------------------
        */
        if (auth()->user()->role === 'kasir') {
            $query->where('user_id', auth()->id());
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
        if ($request->filled('search')) {
            $this->applySearch(
                $query,
                $request->search
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter status transaksi
        |--------------------------------------------------------------------------
        */
        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter status pembayaran
        |--------------------------------------------------------------------------
        */
        if ($request->filled('payment_status')) {
            $query->where(
                'payment_status',
                $request->payment_status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter metode pembayaran
        |--------------------------------------------------------------------------
        */
        if ($request->filled('payment_method')) {
            $query->where(
                'payment_method',
                $request->payment_method
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter tanggal
        |--------------------------------------------------------------------------
        */
        if ($request->filled('date')) {
            $query->whereDate(
                'transaction_date',
                $request->date
            );
        }

        $transactions = $query
            ->latest('transaction_date')
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Riwayat transaksi berhasil diambil',
            'data' => $transactions,
        ]);
    }

    /**
     * Search transaksi berdasarkan:
     * - nomor transaksi
     * - nama customer
     * - nomor HP customer
     * - nama kasir
     * - email kasir
     */
    private function applySearch($query, $search)
    {
        $search = trim($search);

        $query->where(function ($q) use ($search) {

            $q->where(
                'transaction_number',
                'like',
                '%' . $search . '%'
            )

            ->orWhereHas(
                'customer',
                function ($customerQuery) use ($search) {
                    $customerQuery
                        ->where(
                            'name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'phone',
                            'like',
                            '%' . $search . '%'
                        );
                }
            )

            ->orWhereHas(
                'user',
                function ($userQuery) use ($search) {
                    $userQuery
                        ->where(
                            'name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'email',
                            'like',
                            '%' . $search . '%'
                        );
                }
            );
        });

        return $query;
    }

    /**
     * Menampilkan detail transaksi.
     */
    public function show(Transaction $transaction)
    {
        /*
        |--------------------------------------------------------------------------
        | Kasir hanya bisa melihat transaksi miliknya
        |--------------------------------------------------------------------------
        */
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

        /*
        |--------------------------------------------------------------------------
        | BON / CREDIT wajib customer
        |--------------------------------------------------------------------------
        */
        if (
            in_array(
                $paymentMethod,
                ['bon', 'credit']
            ) &&
            empty($validated['customer_id'])
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Customer wajib dipilih untuk transaksi BON atau CREDIT.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | PARTIAL wajib customer
        |--------------------------------------------------------------------------
        */
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

            $transaction = DB::transaction(
                function () use (
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
                    foreach (
                        $validated['items']
                        as $item
                    ) {

                        $product =
                            Product::lockForUpdate()
                                ->findOrFail(
                                    $item['product_id']
                                );

                        /*
                        |--------------------------------------------------------------------------
                        | Tentukan conversion rate dan harga
                        |--------------------------------------------------------------------------
                        */

                        if (
                            (int) $item['unit_id'] ===
                            (int) $product->base_unit_id
                        ) {

                            $conversionRate = 1;

                            $unitPrice =
                                (float) $product->selling_price;

                        } else {

                            $productUnit =
                                ProductUnit::where(
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
                                (float) $productUnit
                                    ->conversion_rate;

                            $unitPrice =
                                (float) $productUnit
                                    ->selling_price;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Quantity
                        |--------------------------------------------------------------------------
                        */

                        $quantity =
                            (float) $item['quantity'];

                        $baseQuantity =
                            $quantity *
                            $conversionRate;

                        /*
                        |--------------------------------------------------------------------------
                        | Cek stok
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $product->stock <
                            $baseQuantity
                        ) {

                            abort(
                                422,
                                'Stok produk "' .
                                $product->name .
                                '" tidak mencukupi.'
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Subtotal
                        |--------------------------------------------------------------------------
                        */

                        $itemSubtotal =
                            $quantity *
                            $unitPrice;

                        $subtotal +=
                            $itemSubtotal;

                        $transactionItems[] = [
                            'product' =>
                                $product,

                            'product_id' =>
                                $product->id,

                            'unit_id' =>
                                $item['unit_id'],

                            'quantity' =>
                                $quantity,

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
                    |--------------------------------------------------------------------------
                    | Total
                    |--------------------------------------------------------------------------
                    */

                    $discount =
                        (float) (
                            $validated['discount']
                            ?? 0
                        );

                    $total =
                        max(
                            $subtotal -
                            $discount,
                            0
                        );

                    $paid =
                        (float) $validated['paid'];

                    /*
                    |--------------------------------------------------------------------------
                    | BON / CREDIT
                    |--------------------------------------------------------------------------
                    */

                    if (
                        in_array(
                            $paymentMethod,
                            ['bon', 'credit']
                        )
                    ) {
                        $paid = 0;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | PARTIAL
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $paymentMethod === 'partial'
                    ) {

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

                    /*
                    |--------------------------------------------------------------------------
                    | CASH / DEBIT
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | QRIS / TRANSFER
                    |--------------------------------------------------------------------------
                    */

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
                    | Payment status
                    |--------------------------------------------------------------------------
                    */

                    if (
                        in_array(
                            $paymentMethod,
                            ['bon', 'credit']
                        )
                    ) {

                        $paymentStatus =
                            'unpaid';

                    } elseif (
                        $paymentMethod ===
                        'partial'
                    ) {

                        $paymentStatus =
                            'partial';

                    } elseif (
                        in_array(
                            $paymentMethod,
                            ['qris', 'transfer']
                        )
                    ) {

                        $paymentStatus =
                            'pending';

                    } else {

                        $paymentStatus =
                            'completed';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Change
                    |--------------------------------------------------------------------------
                    */

                    $change =
                        max(
                            $paid - $total,
                            0
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | Remaining
                    |--------------------------------------------------------------------------
                    */

                    $remainingAmount =
                        max(
                            $total - $paid,
                            0
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | Nomor transaksi
                    |--------------------------------------------------------------------------
                    */

                    $transactionNumber =
                        $this->generateTransactionNumber();

                    /*
                    |--------------------------------------------------------------------------
                    | Create transaction
                    |--------------------------------------------------------------------------
                    */

                    $transaction =
                        Transaction::create([
                            'transaction_number' =>
                                $transactionNumber,

                            'customer_id' =>
                                $validated['customer_id']
                                ?? null,

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
                    | Transaction details
                    |--------------------------------------------------------------------------
                    */

                    foreach (
                        $transactionItems
                        as $item
                    ) {

                        $transaction
                            ->details()
                            ->create([
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
                    | Payment history awal
                    |--------------------------------------------------------------------------
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
                                $paymentMethod ===
                                'partial'
                                    ? 'Pembayaran awal transaksi'
                                    : 'Pembayaran transaksi',
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Kurangi stok
                    |--------------------------------------------------------------------------
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

                        foreach (
                            $transactionItems
                            as $item
                        ) {

                            $product =
                                $item['product'];

                            $product->decrement(
                                'stock',
                                $item['base_quantity']
                            );

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
                }
            );

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
            | Message
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
                $transaction->payment_status ===
                'pending'
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
    public function confirmPayment(
        Transaction $transaction
    ) {

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
            $transaction->payment_status ===
            'completed'
        ) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Pembayaran transaksi ini sudah dikonfirmasi.',
            ], 422);
        }

        try {

            $transaction =
                DB::transaction(
                    function () use (
                        $transaction
                    ) {

                        $transaction =
                            Transaction::lockForUpdate()
                                ->findOrFail(
                                    $transaction->id
                                );

                        /*
                        |--------------------------------------------------------------------------
                        | Cek stok
                        |--------------------------------------------------------------------------
                        */

                        foreach (
                            $transaction->details
                            as $detail
                        ) {

                            $product =
                                Product::lockForUpdate()
                                    ->findOrFail(
                                        $detail->product_id
                                    );

                            if (
                                $product->stock <
                                $detail->base_quantity
                            ) {

                                abort(
                                    422,
                                    'Stok produk "' .
                                    $product->name .
                                    '" tidak mencukupi untuk konfirmasi transaksi.'
                                );
                            }
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Kurangi stok
                        |--------------------------------------------------------------------------
                        */

                        foreach (
                            $transaction->details
                            as $detail
                        ) {

                            $product =
                                Product::lockForUpdate()
                                    ->findOrFail(
                                        $detail->product_id
                                    );

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
                        | Update transaksi
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
                        | Payment history
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
                    }
                );

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
                    'Pembayaran berhasil dikonfirmasi.',
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
        | Cek status
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

            $transaction =
                DB::transaction(
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
                            (float)
                            $transaction
                                ->remaining_amount;

                        $amount =
                            (float)
                            $validated['amount'];

                        /*
                        |--------------------------------------------------------------------------
                        | Tidak boleh lebih
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $amount >
                            $remaining
                        ) {

                            abort(
                                422,
                                'Nominal pembayaran melebihi sisa tagihan.'
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Hitung pembayaran
                        |--------------------------------------------------------------------------
                        */

                        $newPaid =
                            (float)
                            $transaction->paid +
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
                        | Payment history
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

            $transaction->load([
                'customer',
                'user',
                'details.product',
                'details.unit',
                'paymentHistories.user',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Message
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
    public function paymentHistory(
        Request $request
    ) {

        $query = PaymentHistory::with([
            'transaction.customer',
            'transaction.user',
            'user',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Kasir hanya melihat pembayaran dari transaksi miliknya
        |--------------------------------------------------------------------------
        */

        if (
            auth()->user()->role ===
            'kasir'
        ) {

            $query->whereHas(
                'transaction',
                function ($q) {
                    $q->where(
                        'user_id',
                        auth()->id()
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled('search')
        ) {

            $search =
                $request->search;

            $query->whereHas(
                'transaction',
                function ($q) use ($search) {

                    $q->where(
                        'transaction_number',
                        'like',
                        '%' . $search . '%'
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter metode
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled(
                'payment_method'
            )
        ) {

            $query->where(
                'payment_method',
                $request->payment_method
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Filter tanggal
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled('date')
        ) {

            $query->whereDate(
                'payment_date',
                $request->date
            );
        }

        $payments =
            $query
                ->latest('payment_date')
                ->paginate(10);

        return response()->json([
            'success' => true,
            'message' =>
                'Riwayat pembayaran berhasil diambil',
            'data' => $payments,
        ]);
    }

    /**
     * Menampilkan detail riwayat pembayaran
     * berdasarkan transaksi.
     */
    public function paymentHistoryDetail(
        Transaction $transaction
    ) {

        /*
        |--------------------------------------------------------------------------
        | Kasir hanya bisa melihat transaksi miliknya
        |--------------------------------------------------------------------------
        */

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
            'message' =>
                'Detail riwayat pembayaran berhasil diambil',
            'data' => $transaction,
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
     *
     * Data toko sekarang diambil dari tabel settings.
     */
    public function reprint(
        Transaction $transaction
    ) {

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
        | Load transaksi lengkap
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
        | Ambil pengaturan toko
        |--------------------------------------------------------------------------
        */

        $setting = Setting::first();

        /*
        |--------------------------------------------------------------------------
        | Data struk
        |--------------------------------------------------------------------------
        */

        $data = [

            /*
            |--------------------------------------------------------------------------
            | STORE
            |--------------------------------------------------------------------------
            */

            'store' => [

                'name' =>
                    $setting?->store_name ??
                    'BuildPOS',

                'address' =>
                    $setting?->address ??
                    '-',

                'phone' =>
                    $setting?->phone ??
                    '-',

                'receipt_footer' =>
                    $setting?->receipt_footer ??
                    'Terima kasih telah berbelanja.',
            ],

            /*
            |--------------------------------------------------------------------------
            | TRANSACTION
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | CASHIER
            |--------------------------------------------------------------------------
            */

            'cashier' => [

                'id' =>
                    $transaction->user?->id,

                'name' =>
                    $transaction->user?->name,
            ],

            /*
            |--------------------------------------------------------------------------
            | CUSTOMER
            |--------------------------------------------------------------------------
            */

            'customer' => [

                'id' =>
                    $transaction->customer?->id,

                'name' =>
                    $transaction->customer?->name,

                'phone' =>
                    $transaction->customer?->phone,
            ],

            /*
            |--------------------------------------------------------------------------
            | ITEMS
            |--------------------------------------------------------------------------
            */

            'items' =>
                $transaction
                    ->details
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
                    )
                    ->values(),

            /*
            |--------------------------------------------------------------------------
            | SUMMARY
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | PAYMENT HISTORIES
            |--------------------------------------------------------------------------
            */

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

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'message' =>
                'Data reprint transaksi berhasil diambil',
            'data' => $data,
        ]);
    }
}