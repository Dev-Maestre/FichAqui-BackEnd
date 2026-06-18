<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
            $table->json('roles')->nullable()->after('password');
            $table->string('organizer_id')->nullable()->after('roles');
        });

        Schema::create('eventos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->text('description');
            $table->date('date')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('location');
            $table->string('city_id');
            $table->string('organizer_id');
            $table->string('banner')->nullable();
            $table->string('status');
            $table->unsignedInteger('capacity')->default(0);
            $table->string('primary_color');
            $table->string('code')->nullable();
            $table->string('icon')->nullable();
            $table->timestamps();
        });

        Schema::create('barracas', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('evento_id');
            $table->string('name');
            $table->string('category');
            $table->string('responsible');
            $table->string('color');
            $table->string('status');
            $table->unsignedInteger('stock');
            $table->timestamps();

            $table->foreign('evento_id')->references('id')->on('eventos')->cascadeOnDelete();
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_produtos');
        Schema::dropIfExists('produtos');
        Schema::dropIfExists('barracas');
        Schema::dropIfExists('eventos');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'roles', 'organizer_id']);
        });
    }
};
