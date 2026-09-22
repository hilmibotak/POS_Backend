<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\PaymentHistory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_number',
        'customer_id',
        'user_id',
        'subtotal',
        'discount',
        'total',
        'paid',
        'change',
        'payment_method',
        'payment_status',
        'remaining_amount',
        'status',
        'transaction_date',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'change' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(
            TransactionDetail::class
        );
    }

    public function paymentHistories()
    {
        return $this->hasMany(
            PaymentHistory::class
        );
    }
}