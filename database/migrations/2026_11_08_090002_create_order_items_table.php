<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku_snapshot');
            $table->string('product_name_snapshot');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_original_price', 12, 2)->unsigned();
            $table->decimal('unit_discount', 12, 2)->unsigned();
            $table->decimal('unit_final_price', 12, 2)->unsigned();
            $table->decimal('line_total', 12, 2)->unsigned();
            $table->json('applied_discount_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
