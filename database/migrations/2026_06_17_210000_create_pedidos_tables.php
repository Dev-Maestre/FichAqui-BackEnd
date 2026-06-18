<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('evento_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->decimal('total', 10, 2);
            $table->string('status');
            $table->string('qr_code');
            $table->timestamps();

            $table->foreign('evento_id')->references('id')->on('eventos')->cascadeOnDelete();
        });

        Schema::create('pedido_itens', function (Blueprint $table) {
            $table->id();
            $table->string('pedido_id');
            $table->string('sub_produto_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->json('item_snapshot');
            $table->timestamps();

            $table->foreign('pedido_id')->references('id')->on('pedidos')->cascadeOnDelete();
            $table->foreign('sub_produto_id')->references('id')->on('sub_produtos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_itens');
        Schema::dropIfExists('pedidos');
    }
};
