<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // Nomor transaksi
            $table->string('transaction_number')->unique();

            // Pelanggan
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            // Kasir yang melakukan transaksi
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Nilai transaksi
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid', 15, 2)->default(0);
            $table->decimal('change', 15, 2)->default(0);

            // Status transaksi
            $table->enum('status', [
                'completed',
                'cancelled',
            ])->default('completed');

            $table->timestamp('transaction_date')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};