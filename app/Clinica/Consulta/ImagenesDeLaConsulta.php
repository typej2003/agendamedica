<?php

namespace App\Clinica\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\ImagenConsulta;
use App\Models\ImagenDeLaPc;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Las imágenes clínicas de una consulta** (WEB-4.7, ítem 7 del panel del legado: *Exámenes*).
 *
 * ## Dónde vive el dato y de qué cuelga (verificado en la fuente, 2026-10-11)
 *
 * Son filas de **`imagen_consulta`** y **cuelgan de la consulta**: `nrohistoria` + `nroconsulta`
 * (migración núcleo `2026_08_25_055351_create_medical_tables_schema.php:1213`), la misma llave de
 * `consultas`. La fuente lo dice al cargar una imagen desde la solapa de colposcopia: el botón abre el
 * diálogo de archivos (`GetFileOpenName("Seleccione la imagen de la Colposcopía", …)`,
 * `w_consulta_principal.srw:10712`) y guarda **la ruta del archivo elegido** en la fila —
 * `SetItem(llRow, "imagen", ls_Path)`, `:10744`, después de comprobar con
 * `SELECT "imagen_consulta"."imagen" … WHERE nrohistoria AND nroconsulta AND imagen = :ls_Path` que esa
 * misma imagen no esté ya cargada en esa consulta (`:10719-10724`)—. Los DataWindows que las listan son
 * `d_imagenes_consul_pac_*` (`d_imagenes_consul_pac_colposco.srd`, `…_histero`, `…_pelvico`…), todos con
 * `imagen_consulta` como tabla y el `retrieve(historia, consulta)` de la ventana.
 *
 * O sea: **la base guarda una ruta, no el archivo**. En los datos reales esa ruta es absoluta y apunta al
 * disco de la PC del consultorio (`C:\Users\IBYTE\Downloads\20211025_121548.jpg`), así que desde el
 * servidor no se puede servir: copiarla a la nube es un proceso aparte (PLAN-WEB.md §3.9, WEB-T.1). Lo
 * que sube la web guarda la ruta relativa al disco privado en esa **misma** columna y marca la fila con
 * `disco` (migración `2026_10_11_100000_add_archivo_a_imagen_consulta`).
 *
 * ## Las dos formas de fila y cómo se distinguen
 *
 * `deLaConsulta()` devuelve las dos, marcadas con `enElServidor` a partir de `disco` —**la marca de
 * origen**—:
 *
 *  - `disco` con un valor ⇒ la subió la web: `imagen` es una ruta relativa al disco privado `clinica`, el
 *    archivo está en el servidor y la ruta autorizada lo sirve.
 *  - `disco` nulo ⇒ es del escritorio: `imagen` es la ruta absoluta de su PC. La sección la **lista** —el
 *    médico tiene que saber que existe, y la descripción es suya— pero dice que el archivo quedó en el
 *    disco de esa PC; no ofrece un enlace que no puede funcionar.
 *
 * La lectura es de **sólo lectura**; la escritura es `App\Actions\Consulta\GuardarImagenDeLaConsulta`.
 */
final class ImagenesDeLaConsulta
{
    /**
     * Las imágenes de una consulta, las más nuevas primero.
     *
     * El orden es `orden` (el que el escritorio numera por fila, `SetItem(llRow, "orden", orde)`,
     * `w_consulta_principal.srw:10707`) y después la fecha de subida: el `orden` del legado es la
     * posición en su grilla y una fila vieja puede tenerlo en `null`.
     *
     * @return Collection<int,object>
     */
    public function deLaConsulta(string $regMedico, int $nrohistoria, int $nroconsulta): Collection
    {
        $filas = ImagenConsulta::where('reg_medico', $regMedico)
            ->where('nrohistoria', $nrohistoria)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('orden')
            ->orderByDesc('id')
            ->get();

        $originales = $this->nombresOriginales($regMedico, $filas->pluck('id')->all());

        return $filas->map(fn (ImagenConsulta $imagen) => $this->fila($imagen, $originales[(int) $imagen->id] ?? null));
    }

    /**
     * **Las imágenes de un examen de la consulta** (WEB-6.5): las que están etiquetadas para esa solapa
     * (`colposcopia`, …). Misma forma de fila y mismo orden que `deLaConsulta()`.
     *
     * El filtro sale del vocabulario del legado (`TagDeImagen`): la etiqueta canónica del examen **y** sus
     * alias, porque el escritorio marcó la colposcopía de dos maneras distintas.
     *
     * @return Collection<int,object>
     */
    public function deUnExamen(string $regMedico, int $nrohistoria, int $nroconsulta, string $examen): Collection
    {
        $etiquetas = TagDeImagen::aceptadasDe($examen);

        if ($etiquetas === []) {
            return collect();
        }

        $filas = ImagenConsulta::where('reg_medico', $regMedico)
            ->where('nrohistoria', $nrohistoria)
            ->where('nroconsulta', $nroconsulta)
            ->whereIn('observacion', $etiquetas)
            ->orderBy('orden')
            ->orderByDesc('id')
            ->get();

        $originales = $this->nombresOriginales($regMedico, $filas->pluck('id')->all());

        return $filas->map(fn (ImagenConsulta $imagen) => $this->fila($imagen, $originales[(int) $imagen->id] ?? null));
    }

    /**
     * **La imagen que este médico puede ver**, o `null` si no es suya / no es de esta consulta.
     *
     * Es la comprobación de la **ruta autorizada de descarga**, y por eso es una sola:
     *
     *  - se acota a los `reg_medico` del contexto (con `whereIn`, que es como el resto de la web resuelve
     *    "de este médico" cuando tiene más de un registro asignado);
     *  - y se exige que cuelgue **de la consulta que se está mirando** (`nrohistoria` + `nroconsulta`),
     *    que es la que ya se resolvió contra el contexto y el vínculo del paciente. Sin esta segunda
     *    parte, un médico podría pedir por id una imagen suya de **otra** consulta desde la URL de esta
     *    —no es una fuga entre médicos, pero sí una URL que dice una cosa y sirve otra—.
     *
     * Devuelve la fila con el archivo ya resuelto; `null` se traduce en **404** (no se confirma que el
     * archivo exista, misma vara que la consulta ajena).
     *
     * @param  array<int,string>  $registrosMedicos
     */
    public function delContexto(
        array $registrosMedicos,
        int $imagenId,
        int $nrohistoria,
        int $nroconsulta,
    ): ?object {
        $imagen = ImagenConsulta::whereIn('reg_medico', $registrosMedicos)
            ->where('nrohistoria', $nrohistoria)
            ->where('nroconsulta', $nroconsulta)
            ->find($imagenId);

        return $imagen === null
            ? null
            : $this->fila($imagen, $this->nombresOriginales((string) $imagen->reg_medico, [(int) $imagen->id])[(int) $imagen->id] ?? null);
    }

    /**
     * El nombre que tenía el archivo en la PC del consultorio, para las filas que `subir-imagenes` migró (WEB-T.1): lo que
     * el médico reconoce («20211025_121548.jpg») en vez del nombre aleatorio que el servidor le puso al archivo. Sale del
     * registro de importación; sólo el nombre, nunca la ruta de la PC ni la del disco.
     *
     * @param  array<int,int>  $ids
     * @return array<int,string>  id de la fila => nombre
     */
    private function nombresOriginales(string $regMedico, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', 'imagen_consulta')
            ->where('estado', ImagenDeLaPc::SUBIDA)->whereIn('fila_id', $ids)->pluck('ruta_pc', 'fila_id')
            ->map(fn ($ruta) => ArchivosClinicos::nombreVisible($ruta))
            ->filter()->all();
    }

    /**
     * La fila que la sección y la descarga comparten. Es la que decide **de dónde sale el archivo**, y lo
     * decide por una sola cosa: `disco`, que es la **marca de origen**.
     *
     *  - `disco` con un valor ⇒ la subió la web: `imagen` es la ruta relativa al disco privado (la misma
     *    que el escritorio vería si sincronizara la fila) y el archivo se puede servir.
     *  - `disco` nulo ⇒ es del escritorio: `imagen` es la ruta absoluta del disco de la PC
     *    (`C:\Users\IBYTE\Downloads\…`) y no hay nada que servir desde el servidor.
     *
     * La segunda comprobación —que la ruta sea relativa— es defensa en profundidad: una fila del escritorio
     * a la que alguien le hubiera puesto un `disco` a mano no se sirve igual, porque una ruta absoluta
     * (`C:\…` o `/…`) nunca es una ruta de este disco.
     */
    public function fila(ImagenConsulta $imagen, ?string $nombreOriginal = null): object
    {
        $disco = trim((string) $imagen->disco);
        $imagen_guardada = trim((string) $imagen->imagen);
        $enElServidor = $disco !== '' && ArchivosClinicos::esRutaDelDisco($imagen_guardada);

        return (object) [
            'id' => (int) $imagen->id,
            // El modelo, para quien tiene que escribir sobre la fila (el borrado). El resto de la fila son
            // datos ya resueltos para la pantalla y la descarga.
            'modelo' => $imagen,
            // La columna `observacion` tiene **dos contenidos** en el legado y hay que distinguirlos: la
            // **etiqueta** del examen (WEB-6.5) o la **descripción** que teclea el médico en la sección
            // genérica. Sólo una de las dos sale en cada campo (ver `TagDeImagen`).
            'etiqueta' => TagDeImagen::nombre($imagen->observacion),
            'descripcion' => TagDeImagen::esEtiqueta($imagen->observacion)
                ? null
                : TextoDeLaConsulta::limpio($imagen->observacion),
            'fecha' => $imagen->created_at ? (string) $imagen->created_at : null,
            'orden' => $imagen->orden === null ? null : (int) $imagen->orden,
            'enElServidor' => $enElServidor,
            // El disco de Laravel donde vive el archivo (sólo para las de la web). Se guarda en la fila
            // para poder mudar los archivos de disco sin tocar las filas viejas.
            'disco' => $enElServidor ? $disco : null,
            'archivo' => $enElServidor ? $imagen_guardada : null,
            // Un HEIC/HEIF (WEB-T.1: las fotos de un celular que subió `subir-imagenes`) no se muestra en el
            // navegador: se ofrece como descarga, con su Content-Type real.
            'descarga' => $enElServidor && ArchivosClinicos::esDeDescarga($imagen_guardada),
            'tipo' => $enElServidor ? ArchivosClinicos::tipoPorExtension($imagen_guardada) : null,
            // El nombre del archivo tal como lo tenía el consultorio: lo único que el médico reconoce de
            // una imagen vieja y lo único que se muestra (nunca la ruta del sistema).
            'nombre' => $enElServidor && $nombreOriginal !== null
                ? ArchivosClinicos::nombreDeDescarga($nombreOriginal, $imagen_guardada)
                : ArchivosClinicos::nombreVisible($imagen_guardada),
        ];
    }

    /**
     * El número de orden de una imagen nueva: el que sigue a los que ya tiene la consulta.
     *
     * El escritorio numera por cantidad de filas (`orde = dw_53.rowcount()`, `:10701`) y **no** renumera:
     * una fila borrada deja su hueco. Acá se usa `max + 1` para no reusar un número y que el orden de la
     * lista no cambie al borrar.
     */
    public function proximoOrden(string $regMedico, int $nrohistoria, int $nroconsulta): int
    {
        return (int) DB::table('imagen_consulta')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $nrohistoria)
            ->where('nroconsulta', $nroconsulta)
            ->max('orden') + 1;
    }

    /**
     * ¿Quedan imágenes del escritorio en esta consulta? La sección lo dice: son las que se ven en la
     * lista pero no se pueden abrir, y el médico tiene que saber por qué (el archivo está en la PC del
     * consultorio que lo cargó).
     */
    public function hayDelEscritorio(Collection $imagenes): bool
    {
        return $imagenes->contains(fn ($imagen) => ! $imagen->enElServidor);
    }
}
