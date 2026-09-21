<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('unit_id')
                ->nullable()
                ->after('quantity')
                ->constrained('units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('conversion_rate', 15, 3)
                ->default(1)
                ->after('unit_id');

            $table->decimal('base_quantity', 15, 3)
                ->default(0)
                ->after('conversion_rate');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn([
                'unit_id',
                'conversion_rate',
                'base_quantity',
            ]);
        });
    }
};