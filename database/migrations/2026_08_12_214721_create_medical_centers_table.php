<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medical_centers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id'); // Coincide con id() de countries
            $table->integer('state_id');               // Coincide con int(11) de estados
            $table->integer('city_id');                // Coincide con int(11) de cities
            $table->string('name', 200);
            $table->text('address');
            $table->string('phone', 50)->nullable();
            $table->timestamps();

            // Definición exacta de las relaciones
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
            $table->foreign('state_id')->references('id')->on('estados')->onDelete('cascade');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_centers');
    }
};