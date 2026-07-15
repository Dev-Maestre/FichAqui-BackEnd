<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oferta_variantes', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->after('available');
        });

        Schema::table('barracas', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }

    public function down(): void
    {
        Schema::table('barracas', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->after('status');
        });

        Schema::table('oferta_variantes', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};
