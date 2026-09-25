<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->string('store_name')
                ->default('BuildPOS');

            $table->text('address')
                ->nullable();

            $table->string('phone')
                ->nullable();

            $table->text('receipt_footer')
                ->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('settings');
    }
};