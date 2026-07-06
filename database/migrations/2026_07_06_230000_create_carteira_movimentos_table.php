<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carteira_movimentos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('tipo');
            $table->decimal('amount', 10, 2);
            $table->decimal('saldo_apos', 10, 2);
            $table->string('origem_tipo');
            $table->string('origem_id');
            $table->string('descricao')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['origem_tipo', 'origem_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carteira_movimentos');
    }
};
