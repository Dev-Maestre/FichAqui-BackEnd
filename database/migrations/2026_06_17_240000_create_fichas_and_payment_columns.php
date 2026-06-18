<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('qr_code');
            $table->string('card_id')->nullable()->after('payment_method');
            $table->string('payment_status')->nullable()->after('card_id');
        });

        Schema::create('fichas', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('pedido_id');
            $table->string('oferta_variante_id')->nullable();
            $table->string('qr_code')->unique();
            $table->string('status');
            $table->string('item_name');
            $table->string('item_image');
            $table->string('barraca_id');
            $table->string('barraca_name');
            $table->timestamps();

            $table->foreign('pedido_id')->references('id')->on('pedidos')->cascadeOnDelete();
            $table->foreign('oferta_variante_id')->references('id')->on('oferta_variantes')->nullOnDelete();
            $table->foreign('barraca_id')->references('id')->on('barracas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas');

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'card_id', 'payment_status']);
        });
    }
};
