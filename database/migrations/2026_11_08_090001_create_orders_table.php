<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('placed');
            $table->char('currency', 3)->default('MYR');
            $table->decimal('subtotal', 12, 2)->unsigned();
            $table->decimal('discount_total', 12, 2)->unsigned();
            $table->decimal('grand_total', 12, 2)->unsigned();
            $table->string('checkout_idempotency_key', 128)->collation('utf8mb4_bin');
            $table->timestamps();
            $table->unique(['user_id', 'checkout_idempotency_key'], 'orders_customer_idempotency_unique');
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
