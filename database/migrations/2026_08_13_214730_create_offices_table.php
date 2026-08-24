<?php

// database/migrations/2026_01_01_000003_create_offices_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_center_id')->constrained('medical_centers')->onDelete('cascade');
            $table->string('office_number', 50); // Número o identificador de Consultorio/Piso
            $table->string('phone', 50)->nullable();
            $table->text('schedule')->nullable(); // Horarios de atención
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};