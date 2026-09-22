<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payment_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')
                ->constrained('transactions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('payment_method', [
                'cash',
                'transfer',
                'qris',
                'debit',
                'credit',
                'bon',
                'partial',
            ]);

            $table->decimal('amount', 15, 2);

            $table->timestamp('payment_date')
                ->useCurrent();

            $table->text('note')
                ->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_histories');
    }
};