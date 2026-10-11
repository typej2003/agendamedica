<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las imágenes clínicas que sube la web** (WEB-4.7, ítem 7 del panel: *Exámenes*).
 *
 * ## Dónde vive el dato (verificado en la fuente, 2026-10-10)
 *
 * La tabla es **`imagen_consulta`**, que ya existe en la migración núcleo
 * (`2026_08_25_055351_create_medical_tables_schema.php:1213`): `nrohistoria`, `nroconsulta`,
 * `observacion`, `imagen` y `orden`. **Cuelga de la consulta** —`nrohistoria` + `nroconsulta`, las dos
 * `NOT NULL`—, que es la misma llave de `consultas`.
 *
 * La fuente del escritorio lo dice al cargar una imagen: el botón *Agregar* de la solapa de colposcopia
 * abre el diálogo de archivos (`GetFileOpenName("Seleccione la imagen de la Colposcopía", …)`,
 * `w_consulta_principal.srw:10712`) y guarda **la ruta del archivo** en la fila de `imagen_consulta`
 * con `SetItem(llRow, "imagen", ls_Path)` (`:10744`), después de comprobar que esa ruta no esté ya
 * cargada para esa consulta (`SELECT "imagen_consulta"."imagen" … WHERE nrohistoria AND nroconsulta AND
 * imagen = :ls_Path`, `:10719-10724`). O sea: en la base real la columna `imagen` guarda una **ruta
 * absoluta del disco de la PC del consultorio** —`C:\Users\IBYTE\Downloads\20211025_121548.jpg`,
 * confirmado contra los 366 registros del dataset de prueba— y el archivo **no** está en el servidor.
 *
 * ## Qué agrega esta migración (y qué no toca)
 *
 * **Una columna nullable nueva y un índice**, nada más:
 *
 *  - `disco`: el disco de Laravel donde vive el archivo (`clinica`). Se guarda en la fila para poder
 *    mudar los archivos a otro disco —una nube, un servidor de archivos— sin tocar las filas viejas. Es
 *    además **la marca de origen**: una fila con `disco` la subió la web; una con `disco` nulo es del
 *    escritorio.
 *  - El índice `(reg_medico, nrohistoria, nroconsulta)`: el de la lectura de la sección (las imágenes de
 *    **una** consulta) y el de la comprobación de dueño de la descarga. Sin él, la ruta autorizada
 *    recorrería la tabla entera por cada imagen que el médico abre.
 *
 * **`imagen` no se toca y se sigue usando.** Es `varchar(256) NOT NULL` en el esquema del legado y así se
 * queda: lo que cambia es **qué guarda** en cada origen.
 *
 *  - Una fila del **escritorio** guarda la **ruta absoluta del disco de la PC**
 *    (`C:\Users\IBYTE\Downloads\20211025_121548.jpg`), que es lo que el escritorio escribe
 *    (`SetItem(llRow, "imagen", ls_Path)`, `w_consulta_principal.srw:10744`). El archivo no está en el
 *    servidor; copiarlo a la nube es un proceso aparte (WEB-T.1).
 *  - Una fila de la **web** guarda la **ruta relativa al disco privado** —la misma que va en `archivo`—,
 *    que es lo que el nombre de la columna quiere decir y lo que la hace compatible con el escritorio: su
 *    grilla muestra el valor de `imagen` y ve el archivo de esta consulta, con la ruta del servidor en
 *    lugar de la de su PC.
 *
 * **No se agregó una columna `archivo` aparte**: tener dos columnas con la misma ruta (una para el
 * escritorio y otra para la web) obligaría a decidir cuál gana en cada lectura y a mantenerlas en
 * sincronía. `disco` es el desambiguador —una sola columna, un solo significado por origen—, y la lectura
 * es explícita: `App\Clinica\Consulta\ImagenesDeLaConsulta::fila()`.
 *
 * Para MySQL, agregar una columna nullable y un índice es la única operación permitida sobre una tabla
 * que sincroniza datos reales (regla 2 de `AGENTS.md`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imagen_consulta', function (Blueprint $table) {
            $table->string('disco', 40)->nullable()->after('imagen');

            $table->index(['reg_medico', 'nrohistoria', 'nroconsulta'], 'imagen_consulta_consulta_index');
        });
    }

    public function down(): void
    {
        Schema::table('imagen_consulta', function (Blueprint $table) {
            $table->dropIndex('imagen_consulta_consulta_index');
            $table->dropColumn('disco');
        });
    }
};
