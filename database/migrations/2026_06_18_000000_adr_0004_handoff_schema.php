<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cidades', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('state', 2);
            $table->timestamps();
        });

        Schema::table('eventos', function (Blueprint $table) {
            $table->string('cidade')->nullable()->after('city_id');
            $table->string('estado', 2)->nullable()->after('cidade');
            $table->decimal('latitude', 10, 7)->nullable()->after('estado');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('organizer_id');
            $table->string('cpf')->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('cpf');
            $table->string('stall_id')->nullable()->after('birth_date');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('gateway_payment_id')->nullable()->after('payment_status');
            $table->text('pix_qr_code')->nullable()->after('gateway_payment_id');
            $table->text('pix_copy_paste')->nullable()->after('pix_qr_code');
            $table->timestamp('pix_expires_at')->nullable()->after('pix_copy_paste');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn([
                'gateway_payment_id',
                'pix_qr_code',
                'pix_copy_paste',
                'pix_expires_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'cpf', 'birth_date', 'stall_id']);
        });

        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn(['cidade', 'estado', 'latitude', 'longitude']);
        });

        Schema::dropIfExists('cidades');
    }
};
