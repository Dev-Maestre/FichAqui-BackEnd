<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carteira_recargas', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->string('payment_status');
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_order_id')->nullable();
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->timestamp('pix_expires_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->index('gateway_payment_id');
            $table->index('gateway_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carteira_recargas');
    }
};
