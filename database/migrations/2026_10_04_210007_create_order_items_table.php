<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Product snapshot taken when the order is placed.
            $table->string('product_name');
            $table->string('sku', 64);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE order_items
            ADD CONSTRAINT order_items_quantity_check CHECK (quantity >= 1),
            ADD CONSTRAINT order_items_amounts_check CHECK (
                unit_price >= 0 AND (unit_cost IS NULL OR unit_cost >= 0) AND discount_amount >= 0 AND line_total >= 0
            )');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
