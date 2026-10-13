<?php

namespace App\Actions\Imagenes;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Archivos\TipoRealDeImagen;
use App\Models\ImagenConsulta;
use App\Models\ImagenDeLaPc;
use App\Sync\ImagenesPc\CatalogoDeImagenes;
use App\Sync\ImagenesPc\ReferenciasDeImagenes;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * **Recibir una imagen que vivía en el disco de la PC del consultorio** (WEB-T.1): la guarda en el disco privado
 * `clinica` y deja registrado que esa celda del legado ya tiene su archivo en la nube.
 *
 * La llama el endpoint `POST /api/sync/imagenes/subir` (que la herramienta `bridge/subir-imagenes.js` usa) y nada más. Es
 * la **única** regla de la importación, igual que `GuardarImagenDeLaConsulta` lo es de la subida de la web (y reutiliza su
 * política de archivos, `ArchivosClinicos`).
 *
 * ## Qué comprueba, en este orden (y con qué código responde)
 *
 *  1. La (tabla, columna) está en la lista blanca (`config/imagenes_pc.php`) — `tabla_no_permitida`.
 *  2. La fila existe y **es de este médico** — `fila_inexistente` (no se confirma si es de otro).
 *  3. La ruta que dice el cliente es la que la fila tiene hoy — `fila_cambio` (el escritorio la cambió en el medio).
 *  4. Hay un archivo, entra en el tope y su contenido **es una imagen** (cabecera, no el nombre ni el MIME del cliente) —
 *     `archivo_ausente`, `demasiado_grande`, `tipo_no_permitido`.
 *  5. El `sha256` que mandó el cliente es el del contenido recibido — `sha256_no_coincide`.
 *
 * ## Idempotencia
 *
 * Si la celda ya está `subida` con el **mismo contenido** (mismo `sha256`) y el archivo está en el disco, no se guarda nada y
 * responde `ya_estaba`. Si el contenido es otro (o el archivo se perdió del disco) se guarda el nuevo y se borra el anterior
 * (`reemplazada`). Dos subidas simultáneas de la misma celda no duplican: la segunda tropieza con el índice único, vuelve a
 * leer y responde `ya_estaba` (y borra su copia).
 *
 * ## El contrato con la fila legada
 *
 *  - **`imagen_consulta`** (la web ya sabe servirla, WEB-4.7): la fila pasa a `imagen` = ruta **relativa al disco** y
 *    `disco` = `clinica`, que es exactamente lo que escribe la web, así que `ImagenesDeLaConsulta` la muestra como
 *    «En el servidor» y la ruta autorizada la sirve. Deja el rastro `updated` por columna en `sync_changes`, con
 *    `source = migracion` (distinto de `web`/`mobile`/`escritorio`: no es una edición de un usuario y no entra en el
 *    last-write-wins de ninguno de los dos).
 *  - **Las demás tablas NO se tocan**: el escritorio sigue leyendo esa columna y su subida podría revertir cualquier cambio.
 *    El archivo queda sólo en `imagenes_de_la_pc` (`ImagenesMigradas` lo resuelve cuando la web lo muestre).
 *
 * Todo lo que escribe la base va en una transacción y, si algo falla, **se borra el archivo recién guardado** (el patrón de
 * `GuardarImagenDeLaConsulta`: un archivo clínico sin dueño es un dato de salud huérfano).
 */
final class SubirImagenDeLaPc
{
    /** El `source` del rastro en `sync_changes`: la migración de archivos, no una edición de usuario. */
    public const ORIGEN = 'migracion';

    public function __construct(
        private CatalogoDeImagenes $catalogo,
        private ReferenciasDeImagenes $referencias,
    ) {
    }

    public function ejecutar(
        string $regMedico,
        string $tabla,
        int $filaId,
        string $columna,
        string $rutaPc,
        string $sha256,
        ?UploadedFile $archivo,
        ?string $equipo = null,
    ): ResultadoDeImagenDeLaPc {
        $rutaPc = trim($rutaPc);
        $sha256 = strtolower(trim($sha256));

        if (! $this->catalogo->permite($tabla, $columna)) {
            return ResultadoDeImagenDeLaPc::error('tabla_no_permitida', 422, 'Esa tabla o columna no guarda imágenes de la PC.');
        }

        $celda = $this->referencias->celda($regMedico, $tabla, $filaId, $columna);
        if ($celda === null) {
            return ResultadoDeImagenDeLaPc::error('fila_inexistente', 404, 'No hay una fila así para este médico.');
        }

        $previo = ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', $tabla)
            ->where('fila_id', $filaId)->where('columna', $columna)->first();

        // La ruta que el cliente cree que tiene la fila tiene que ser la de hoy; o, si la fila ya se migró
        // (`imagen_consulta` guarda ahora la ruta de la nube), la de la PC que quedó registrada.
        $valor = (string) $celda->ruta;
        $yaMigrada = $previo !== null && $previo->ruta !== null && $previo->ruta === $valor && $previo->ruta_pc === $rutaPc;
        if ($valor === '' || strtolower($valor) === 'none') {
            return ResultadoDeImagenDeLaPc::error('fila_cambio', 409, 'La fila ya no tiene una ruta de archivo.');
        }
        if ($valor !== $rutaPc && ! $yaMigrada) {
            return ResultadoDeImagenDeLaPc::error('fila_cambio', 409, 'La ruta de la fila cambió desde que se pidió la lista.');
        }

        if ($archivo === null) {
            return ResultadoDeImagenDeLaPc::error('archivo_ausente', 422, 'Falta el archivo.');
        }
        if (! $archivo->isValid()) {
            $error = $archivo->getError();

            return in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? ResultadoDeImagenDeLaPc::error('limite_del_servidor', 413, 'El servidor no acepta archivos de ese tamaño (upload_max_filesize / post_max_size).')
                : ResultadoDeImagenDeLaPc::error('archivo_invalido', 422, 'El archivo no llegó completo.');
        }

        $tamano = (int) $archivo->getSize();
        if ($tamano > ArchivosClinicos::maximoBytes()) {
            return ResultadoDeImagenDeLaPc::error('demasiado_grande', 422, 'El archivo pesa más de ' . ArchivosClinicos::textoMaximo() . '.');
        }

        // El tipo REAL, por la cabecera: ni el nombre ni el MIME que declara el cliente cuentan (trampa 31).
        $tipo = TipoRealDeImagen::desdeArchivo((string) $archivo->getRealPath());
        if ($tipo === null) {
            return ResultadoDeImagenDeLaPc::error('tipo_no_permitido', 422, 'El archivo no es una imagen de un tipo aceptado (JPG, PNG, GIF, WEBP o HEIC).');
        }

        $hash = (string) hash_file('sha256', (string) $archivo->getRealPath());
        if (! hash_equals($hash, $sha256)) {
            return ResultadoDeImagenDeLaPc::error('sha256_no_coincide', 422, 'El contenido recibido no coincide con el sha256 declarado.');
        }

        $disco = Storage::disk(ArchivosClinicos::DISCO);

        // Idempotente: lo mismo otra vez no se guarda dos veces.
        if ($previo !== null && $previo->estaSubida() && $previo->sha256 === $hash && $previo->ruta !== null && $disco->exists($previo->ruta)) {
            $this->repararFilaSiHaceFalta($regMedico, $tabla, $filaId, $previo->ruta, $valor);

            return ResultadoDeImagenDeLaPc::exito('ya_estaba', $this->datos($previo), 'Ese archivo ya estaba subido.');
        }

        $ruta = ArchivosClinicos::rutaImportada($regMedico, $tabla, $tipo['extension']);
        if (! $disco->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private'])) {
            return ResultadoDeImagenDeLaPc::error('error_guardando', 500, 'No se pudo guardar el archivo en el disco del servidor.');
        }

        $anterior = $previo !== null && $previo->estaSubida() ? $previo->ruta : null;
        $reemplaza = $anterior !== null;

        try {
            $registro = DB::transaction(function () use ($regMedico, $tabla, $filaId, $columna, $rutaPc, $ruta, $tipo, $tamano, $hash, $equipo, $valor) {
                $registro = ImagenDeLaPc::firstOrNew([
                    'reg_medico' => $regMedico, 'tabla' => $tabla, 'fila_id' => $filaId, 'columna' => $columna,
                ]);
                $registro->fill([
                    'ruta_pc' => $rutaPc,
                    'estado' => ImagenDeLaPc::SUBIDA,
                    'motivo' => null,
                    'disco' => ArchivosClinicos::DISCO,
                    'ruta' => $ruta,
                    'tipo' => $tipo['mime'],
                    'tamano' => $tamano,
                    'sha256' => $hash,
                    'equipo' => $equipo !== null ? mb_substr($equipo, 0, 100) : null,
                ])->save();

                if ($this->catalogo->reescribe($tabla)) {
                    $this->reescribirFila($regMedico, $tabla, $filaId, $ruta);
                }

                return $registro;
            });
        } catch (QueryException $e) {
            // Dos subidas de la misma celda a la vez: gana la primera. Si la que quedó es la misma, esto es un `ya_estaba`.
            $disco->delete($ruta);
            $otro = ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', $tabla)
                ->where('fila_id', $filaId)->where('columna', $columna)->first();
            if ($previo === null && $otro !== null && $otro->estaSubida() && $otro->sha256 === $hash) {
                return ResultadoDeImagenDeLaPc::exito('ya_estaba', $this->datos($otro), 'Ese archivo ya estaba subido.');
            }

            throw $e;
        } catch (Throwable $e) {
            // El archivo ya está en el disco y la base no quedó: se borra antes de propagar.
            $disco->delete($ruta);

            throw $e;
        }

        // El archivo anterior (la ruta cambió o el contenido es otro) ya no lo referencia nadie.
        if ($anterior !== null && $anterior !== $ruta && ArchivosClinicos::esRutaDelDisco($anterior)) {
            $disco->delete($anterior);
        }

        return ResultadoDeImagenDeLaPc::exito($reemplaza ? 'reemplazada' : 'subida', $this->datos($registro), $reemplaza ? 'Archivo reemplazado.' : 'Archivo subido.');
    }

    /** `imagen_consulta`: la fila pasa a apuntar al archivo de la nube, con el rastro por columna. */
    private function reescribirFila(string $regMedico, string $tabla, int $filaId, string $ruta): void
    {
        // Sólo `imagen_consulta` reescribe (lo dice `config/imagenes_pc.php`); cualquier otra tabla se deja como está.
        if ($tabla !== 'imagen_consulta') {
            return;
        }

        $cuando = Carbon::now();
        ImagenConsulta::where('id', $filaId)->where('reg_medico', $regMedico)->update([
            'imagen' => $ruta,
            'disco' => ArchivosClinicos::DISCO,
        ]);

        RegistroDeCambios::registrar($tabla, $filaId, $regMedico, 'imagen', $ruta, $cuando, self::ORIGEN);
        RegistroDeCambios::registrar($tabla, $filaId, $regMedico, 'disco', ArchivosClinicos::DISCO, $cuando, self::ORIGEN);
    }

    /** Si el archivo ya estaba pero la fila volvió a tener la ruta de la PC (el escritorio la re-subió), se vuelve a apuntar. */
    private function repararFilaSiHaceFalta(string $regMedico, string $tabla, int $filaId, string $ruta, string $valorActual): void
    {
        if ($this->catalogo->reescribe($tabla) && $valorActual !== $ruta) {
            $this->reescribirFila($regMedico, $tabla, $filaId, $ruta);
        }
    }

    /** @return array<string,mixed> lo que se informa del registro (nunca la ruta del sistema ni la de la PC). */
    private function datos(ImagenDeLaPc $registro): array
    {
        return [
            'tabla' => $registro->tabla,
            'fila_id' => (int) $registro->fila_id,
            'columna' => $registro->columna,
            'tipo' => $registro->tipo,
            'tamano' => (int) $registro->tamano,
            'sha256' => $registro->sha256,
        ];
    }
}
