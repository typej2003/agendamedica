<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->integer('id')->autoIncrement();
            $table->integer('state_id');
            $table->string('name', 200);
            $table->boolean('capital')->default(false);
            $table->timestamps();

            // Clave foránea apuntando a la tabla 'estados'
            $table->foreign('state_id')->references('id')->on('estados')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};