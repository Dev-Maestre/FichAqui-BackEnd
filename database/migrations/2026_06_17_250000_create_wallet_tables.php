<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carteiras', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('cartoes_salvos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->string('last_four', 4);
            $table->string('holder_name');
            $table->boolean('is_default')->default(false);
            $table->string('gateway_token')->nullable();
            $table->timestamps();
        });

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->string('oferta_variante_id')->nullable()->after('sub_produto_id');

            $table->foreign('oferta_variante_id')
                ->references('id')
                ->on('oferta_variantes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropForeign(['oferta_variante_id']);
            $table->dropColumn('oferta_variante_id');
        });

        Schema::dropIfExists('cartoes_salvos');
        Schema::dropIfExists('carteiras');
    }
};
