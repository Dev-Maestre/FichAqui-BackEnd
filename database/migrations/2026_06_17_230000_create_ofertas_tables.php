<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('evento_id');
            $table->string('barraca_id');
            $table->string('catalogo_produto_id');
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->foreign('evento_id')->references('id')->on('eventos')->cascadeOnDelete();
            $table->foreign('barraca_id')->references('id')->on('barracas')->cascadeOnDelete();
            $table->foreign('catalogo_produto_id')->references('id')->on('catalogo_produtos')->cascadeOnDelete();
            $table->unique(['barraca_id', 'catalogo_produto_id']);
        });

        Schema::create('oferta_variantes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('oferta_id');
            $table->string('variant_template_id');
            $table->decimal('price', 10, 2);
            $table->boolean('available')->default(true);
            $table->string('badge')->nullable();
            $table->timestamps();

            $table->foreign('oferta_id')->references('id')->on('ofertas')->cascadeOnDelete();
            $table->foreign('variant_template_id')->references('id')->on('variant_templates')->cascadeOnDelete();
            $table->unique(['oferta_id', 'variant_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oferta_variantes');
        Schema::dropIfExists('ofertas');
    }
};
