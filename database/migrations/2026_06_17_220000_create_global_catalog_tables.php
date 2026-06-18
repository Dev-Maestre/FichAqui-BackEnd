<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('icon');
            $table->string('color');
            $table->timestamps();
        });

        Schema::create('catalogo_produtos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('categoria_id');
            $table->string('name');
            $table->text('description');
            $table->string('image');
            $table->string('badge')->nullable();
            $table->timestamps();

            $table->foreign('categoria_id')->references('id')->on('categorias')->cascadeOnDelete();
        });

        Schema::create('variant_templates', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('catalogo_produto_id');
            $table->string('slug');
            $table->string('label');
            $table->timestamps();

            $table->foreign('catalogo_produto_id')->references('id')->on('catalogo_produtos')->cascadeOnDelete();
            $table->unique(['catalogo_produto_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_templates');
        Schema::dropIfExists('catalogo_produtos');
        Schema::dropIfExists('categorias');
    }
};
