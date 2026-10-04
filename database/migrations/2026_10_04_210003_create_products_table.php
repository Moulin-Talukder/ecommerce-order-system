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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id');
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku', 64)->unique();
            $table->string('short_description', 500)->nullable();
            $table->mediumText('description')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('selling_price', 12, 2);
            $table->decimal('discount_price', 12, 2)->nullable();
            $table->boolean('manage_stock')->default(true);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->string('stock_status', 32)->default('in_stock');
            $table->unsignedSmallInteger('low_stock_threshold')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->decimal('weight', 8, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index(['status', 'stock_status']);
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE products
            ADD CONSTRAINT products_selling_price_check CHECK (selling_price >= 0),
            ADD CONSTRAINT products_purchase_price_check CHECK (purchase_price IS NULL OR purchase_price >= 0),
            ADD CONSTRAINT products_discount_price_check CHECK (discount_price IS NULL OR (discount_price >= 0 AND discount_price < selling_price))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
