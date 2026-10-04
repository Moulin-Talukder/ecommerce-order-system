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
        Schema::create('courier_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('courier', 32)->default('steadfast');
            $table->string('invoice', 64);
            $table->string('consignment_id', 64)->nullable();
            $table->string('tracking_code', 64)->nullable()->index();
            $table->string('status', 50)->default('pending');
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['courier', 'invoice']);
            $table->unique(['courier', 'consignment_id']);
            $table->index(['courier', 'status']);
        });

        DB::statement('ALTER TABLE courier_shipments
            ADD CONSTRAINT courier_shipments_amounts_check CHECK (cod_amount >= 0 AND (delivery_fee IS NULL OR delivery_fee >= 0))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courier_shipments');
    }
};
