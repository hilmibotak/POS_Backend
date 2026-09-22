<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'customer_id',
        'user_id',
        'payment_method',
        'amount',
        'payment_date',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(
            Transaction::class
        );
    }

    public function customer()
    {
        return $this->belongsTo(
            Customer::class
        );
    }

    public function user()
    {
        return $this->belongsTo(
            User::class
        );
    }
}