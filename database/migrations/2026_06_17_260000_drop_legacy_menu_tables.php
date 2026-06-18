<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropForeign(['sub_produto_id']);
            $table->dropColumn('sub_produto_id');
        });

        Schema::dropIfExists('sub_produtos');
        Schema::dropIfExists('produtos');
    }

    public function down(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('evento_id');
            $table->string('barraca_id');
            $table->string('name');
            $table->text('description');
            $table->string('category');
            $table->string('image');
            $table->string('badge')->nullable();
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->foreign('evento_id')->references('id')->on('eventos')->cascadeOnDelete();
            $table->foreign('barraca_id')->references('id')->on('barracas')->cascadeOnDelete();
        });

        Schema::create('sub_produtos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('produto_id');
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->boolean('available')->default(true);
            $table->string('badge')->nullable();
            $table->timestamps();

            $table->foreign('produto_id')->references('id')->on('produtos')->cascadeOnDelete();
        });

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->string('sub_produto_id')->nullable()->after('pedido_id');
            $table->foreign('sub_produto_id')->references('id')->on('sub_produtos');
        });
    }
};
