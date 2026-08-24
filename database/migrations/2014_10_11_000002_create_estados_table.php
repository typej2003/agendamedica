<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('estados', function (Blueprint $table) {
            $table->integer('id')->autoIncrement();
            $table->string('name', 250);
            $table->string('iso_3166-2', 4);
            $table->unsignedBigInteger('country_id'); // Debe coincidir con el id() de countries

            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estados');
    }
};