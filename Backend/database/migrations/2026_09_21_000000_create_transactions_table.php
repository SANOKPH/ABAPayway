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
            $table->string('tran_id', 20)->unique();
            $table->string('name', 100);
            $table->string('account_number', 50);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('KHR');
            $table->string('status', 20)->index();
            $table->string('payway_response_code', 10)->nullable();
            $table->string('payway_apv', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
