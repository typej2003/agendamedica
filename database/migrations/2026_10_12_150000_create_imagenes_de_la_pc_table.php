<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El registro de las imágenes que viven en el disco de la PC del consultorio y se suben a la nube** (WEB-T.1).
 *
 * ## Por qué existe
 *
 * El escritorio guarda en la base la **ruta** de cada imagen (`imagen_consulta.imagen`, `imagen_pacientes.imagen` e
 * `imagen2`, `imagenes.imagen`, `pacientes.foto_pac`…), no el archivo. La herramienta `bridge/subir-imagenes.exe` corre en
 * la PC del consultorio, pide al API qué rutas faltan, lee cada archivo y lo sube. Esta tabla es **la memoria de ese
 * proceso**: qué ya se subió (y dónde quedó en el disco privado `clinica`) y qué se decidió **omitir** (no existe en la
 * PC, no es una imagen, pesa demasiado) para no reintentarlo cada vez.
 *
 * Es una tabla **nueva de AppDDR**, no del legado: el escritorio no la conoce, no entra en `config/sync_legado.php` y el
 * móvil no la baja. Sirve para **no tocar las columnas legadas** de las tablas que la web todavía no muestra: el escritorio
 * sigue leyéndolas y su subida podría revertir cualquier cambio. (La única que se reescribe es `imagen_consulta`, que la
 * web ya sabe servir; ver `App\Actions\Imagenes\SubirImagenDeLaPc`.)
 *
 * ## Las columnas
 *
 *  - `reg_medico`, `tabla`, `fila_id`, `columna`: de qué dato legado es el archivo. `fila_id` es el `id` de la fila en
 *    el API. **Único por los cuatro**: un dato tiene a lo sumo un archivo.
 *  - `ruta_pc`: la ruta tal como estaba en la fila (recortada), para saber si la fila cambió después (si el escritorio
 *    sube otra ruta, el archivo anterior ya no es el de la fila y vuelve a quedar pendiente).
 *  - `estado`: `subida` u `omitida`. `motivo`: por qué se omitió (`no_existe`, `tipo_no_permitido`, `demasiado_grande`…).
 *  - `disco` y `ruta`: dónde quedó el archivo (la ruta es relativa al disco, nunca del sistema). Nulos si se omitió.
 *  - `tipo` (MIME real, por contenido), `tamano` (bytes) y `sha256`: lo que se subió.
 *  - `equipo`: el `X-Equipo` de quien subió (bitácora).
 *
 * Los textos largos son `string` de 500 (el `foto_pac` del legado es `char(300)` y el `logo` `char(500)`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imagenes_de_la_pc', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            $table->string('tabla', 64);
            $table->unsignedBigInteger('fila_id');
            $table->string('columna', 64);
            $table->string('ruta_pc', 500);
            $table->string('estado', 12);
            $table->string('motivo', 40)->nullable();
            $table->string('disco', 40)->nullable();
            $table->string('ruta', 500)->nullable();
            $table->string('tipo', 60)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('equipo', 100)->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'tabla', 'fila_id', 'columna'], 'imagenes_de_la_pc_dato_unico');
            $table->index(['reg_medico', 'estado'], 'imagenes_de_la_pc_estado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imagenes_de_la_pc');
    }
};
