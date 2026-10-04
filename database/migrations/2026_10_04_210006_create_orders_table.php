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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('customer_id');

            // Customer / delivery snapshot taken when the order is placed.
            $table->string('customer_name', 150);
            $table->string('customer_phone', 20)->index();
            $table->string('customer_alternate_phone', 20)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('shipping_address', 500);
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_area', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();

            // Totals, calculated server-side.
            $table->char('currency', 3)->default('BDT');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);

            $table->string('payment_method', 32);
            $table->string('payment_status', 32)->default('pending');
            $table->string('status', 32)->default('pending');
            $table->string('source', 32)->default('website');
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
            $table->index(['source', 'created_at']);
            $table->index('created_at');
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE orders
            ADD CONSTRAINT orders_amounts_check CHECK (
                subtotal >= 0 AND discount_total >= 0 AND delivery_charge >= 0 AND total >= 0 AND amount_paid >= 0
            )');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
