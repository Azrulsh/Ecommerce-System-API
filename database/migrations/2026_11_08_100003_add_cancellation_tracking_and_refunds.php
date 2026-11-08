<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_restored_at')->nullable();
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reference', 50)->unique();
            $table->decimal('amount', 12, 2)->unsigned();
            $table->string('status', 20);
            $table->timestamp('refunded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('stock_restored_at'));
    }
};
