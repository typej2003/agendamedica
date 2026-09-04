<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('upload_servers', function (Blueprint $table) {
            $table->id();
            
            // Define el tipo de registro/módulo subido (ej: 'consultas', 'cola', 'pacientes')
            $table->string('entity_type')->index(); 

            // Estado o subcategoría opcional (ej: 'nuevos', 'pendientes', 'actualizados')
            $table->string('batch_type')->nullable(); 

            // Cantidad de elementos procesados o subidos en este lote
            $table->integer('records_count')->default(0); 

            // Guardar la referencia del último registro subido (ID local o UUID)
            $table->string('last_record_id')->nullable(); 

            // Fecha/Hora del último registro procesado (en caso de sincronización por timestamp)
            $table->timestamp('last_record_timestamp')->nullable(); 

            // Estado de la subida (ej: 'completed', 'failed', 'in_progress')
            $table->string('status')->default('completed'); 

            // Información adicional en JSON (ej: errores, IDs procesados, respuesta del API)
            $table->json('payload')->nullable(); 

            // Muestra la fecha y hora exacta en que se registró la subida
            $table->timestamps(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upload_servers');
    }
};