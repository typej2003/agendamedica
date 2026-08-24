<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seg_emps', function (Blueprint $table) {
            $table->string('codesegemp', 3)->primary();
            $table->string('nombre', 150);
            $table->string('rif', 50)->nullable();
            $table->string('direccion', 350)->nullable();
            $table->string('telef', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seg_emps');
    }
};