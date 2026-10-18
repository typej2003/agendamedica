<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las **imágenes adjuntas a un comentario** de "Ayúdanos a mejorar" (O-25): la captura que el usuario pega o
 * elige cuando lo que quiere contar es «esto se ve mal».
 *
 * Tabla **nueva de AppDDR** como `comentarios` —no del legado, no entra al sync del escritorio ni del app—, así
 * que se diseña libre. Dos decisiones que valen la pena mirar acá:
 *
 *  - **Tabla y no una columna JSON** (que también era posible): cada archivo tiene su fila, su disco, su ruta y
 *    su tipo, y el borrado en cascada del comentario se lleva las filas. Con un JSON la lista sería un valor
 *    opaco que no se puede consultar ni contar, y el borrado de un archivo —que además hay que hacer en el
 *    disco— dependería de leer y reescribir el JSON entero.
 *  - **`ON DELETE CASCADE` de verdad**: si algún día se borra la fila del comentario por SQL, sus imágenes no
 *    quedan colgando. Los archivos del disco los borra la Action (`BorrarImagenesDelComentario`), que es lo
 *    único que una FK no puede hacer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comentario_imagenes', function (Blueprint $table) {
            $table->id();

            // La cascada es la del comentario: sus adjuntos no tienen vida propia.
            $table->foreignId('comentario_id')->constrained('comentarios')->cascadeOnDelete();

            // El disco de Laravel donde está el archivo (`comentarios` → `storage/app/comentarios`, privado y
            // fuera de `public/`) y la ruta relativa dentro de él. Se guardan las dos —como `imagen_consulta`—
            // para que un día se pueda mudar el disco sin tocar las filas viejas.
            $table->string('disco', 20)->default('comentarios');
            $table->string('ruta', 255);

            // El tipo real con el que se guardó (`image/png`…), el tamaño en bytes y el nombre que traía el
            // archivo en la máquina del usuario. El nombre **no** se usa para el archivo (lo arma el servidor);
            // es lo que se muestra y lo que se pone en la descarga.
            $table->string('tipo', 60)->nullable();
            $table->unsignedInteger('tamano')->nullable();
            $table->string('nombre_original', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comentario_imagenes');
    }
};
