<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mercadopago_customer_id')->nullable()->after('cpf');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('save_card')->default(false)->after('card_id');
        });

        Schema::table('carteira_recargas', function (Blueprint $table) {
            $table->boolean('save_card')->default(false)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('carteira_recargas', function (Blueprint $table) {
            $table->dropColumn('save_card');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('save_card');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mercadopago_customer_id');
        });
    }
};
