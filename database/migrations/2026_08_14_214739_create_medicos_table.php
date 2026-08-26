<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('name', 200);
            $table->string('lastname', 200)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->unique()->nullable();
            $table->string('password')->nullable();
            $table->text('biography')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('set null');
            $table->decimal('consultation_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicos');
    }
};