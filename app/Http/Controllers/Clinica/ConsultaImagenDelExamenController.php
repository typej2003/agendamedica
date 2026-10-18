<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\ElegirLaImagenDelExamen;
use App\Actions\Consulta\GuardarDibujoDelExamen;
use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\ImagenDelExamen;
use App\Clinica\Consulta\ImagenDelExamenDeLaConsulta;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * **La imagen del examen con sus dos modos** (O-09, antes A-13): servir, guardar y quitar el trazo de cada lienzo —una
 * variante del esquema o una foto del estudio—, recordar la imagen base del esquema y la **elección** entre la imagen
 * predeterminada y el videocolposcopio.
 *
 * La tarjeta es la de la solapa de la colposcopía (y, desde O-10, la de la histeroscopía); el lienzo es
 * `public/js/clinica/lienzo.js` con su inicialización en `imagen-del-examen.js`. Las reglas de escritura son las Actions
 * `GuardarDibujoDelExamen`, `ElegirLaImagenDelExamen` y `OlvidarElTrazoDeLaFoto`.
 *
 * Como las mamas (WEB-11.10), el archivo vive en el disco privado `clinica` y sólo se sirve por esta ruta autorizada: el
 * 404 es del médico ajeno y el 403, de la secretaría (el grupo de rutas de la consulta).
 *
 * El lienzo viaja en la URL con dos formas (`{lienzo}`): `nulipara`/`multipara` (una variante del esquema) o
 * `foto-12` (la foto 12 del estudio). Las dos se validan contra el catálogo del examen y contra las fotos de la consulta.
 */
class ConsultaImagenDelExamenController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private ConsultasDelContexto $consultas,
        private ImagenDelExamenDeLaConsulta $imagenes,
    ) {
    }

    public function ver(Request $request, Consulta $consulta, string $examen, string $lienzo)
    {
        $consulta = $this->delContexto($request, $consulta);
        $lienzo = $this->lienzo($request, $consulta, $examen, $lienzo);

        $dibujo = $this->imagenes->dibujos($consulta, $examen)->get($lienzo['clave']);
        abort_unless(
            $dibujo && $dibujo->archivo !== null && Storage::disk(ArchivosClinicos::DISCO)->exists($dibujo->archivo),
            404,
        );

        return Storage::disk(ArchivosClinicos::DISCO)->response($dibujo->archivo, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * Guarda el trazo y/o la base del esquema. El `dibujo` es **opcional**: sin archivo se recuerda la base y se conserva
     * el trazo anterior (es lo que manda la pantalla al cambiar la imagen base).
     */
    public function guardar(Request $request, Consulta $consulta, string $examen, string $lienzo, GuardarDibujoDelExamen $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);
        $lienzo = $this->lienzo($request, $consulta, $examen, $lienzo);

        $request->validate([
            'base' => ['nullable', Rule::in(ImagenDelExamen::BASES)],
            'dibujo' => ['nullable', 'file', 'max:' . ArchivosClinicos::MAXIMO_KB],
        ]);

        $base = $request->input('base') === null ? null : (string) $request->input('base');

        try {
            if ($lienzo['variante'] !== null) {
                $dibujo = $guardar->guardarEsquema($consulta, $examen, $lienzo['variante'], $base, $request->file('dibujo'));
            } else {
                $dibujo = $guardar->guardarFoto($consulta, $examen, (int) $lienzo['foto'], $request->file('dibujo'));
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => 'No se pudo guardar el dibujo en el servidor.'], 500);
        }

        return response()->json([
            'guardado' => true,
            'base' => $dibujo?->base ?? ImagenDelExamen::BASE_POR_DEFECTO,
            'dibujo' => $dibujo?->archivo !== null,
        ]);
    }

    /** Quita el trazo del lienzo (en el esquema, recuerda la base que quedó elegida). */
    public function quitar(Request $request, Consulta $consulta, string $examen, string $lienzo, GuardarDibujoDelExamen $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);
        $lienzo = $this->lienzo($request, $consulta, $examen, $lienzo);

        $request->validate(['base' => ['nullable', Rule::in(ImagenDelExamen::BASES)]]);
        $base = $request->query('base') === null ? null : (string) $request->query('base');

        try {
            if ($lienzo['variante'] !== null) {
                $guardar->quitarEsquema($consulta, $examen, $lienzo['variante'], $base);
            } else {
                $guardar->quitarFoto($consulta, $examen, (int) $lienzo['foto']);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['quitado' => true]);
    }

    /**
     * **La elección del radiobutón** (O-09): qué imagen del examen se ve y se imprime, y cuál de las fotos se marca.
     * Habla JSON porque la pantalla la guarda sin recargar (cambiar de modo no puede perder el texto sin guardar).
     */
    public function elegir(Request $request, Consulta $consulta, string $examen, ElegirLaImagenDelExamen $elegir)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(ImagenDelExamen::existe($examen), 404);

        $datos = $request->validate([
            'modo' => ['required', Rule::in(ImagenDelExamen::MODOS)],
            'imagen_id' => ['nullable', 'integer'],
        ]);

        $imagen = isset($datos['imagen_id']) ? (int) $datos['imagen_id'] : null;

        // La foto elegida tiene que ser **de este examen** y de esta consulta: si no, no se toca la elección.
        if ($imagen !== null && $this->foto($request, $consulta, $examen, $imagen) === null) {
            return response()->json(['mensaje' => 'Esa foto no es de este examen.'], 422);
        }

        $eleccion = $elegir->ejecutar($consulta, $examen, (string) $datos['modo'], $imagen);

        return response()->json([
            'guardado' => true,
            'modo' => $eleccion->modo,
            'imagen_id' => $eleccion->imagen_id === null ? null : (int) $eleccion->imagen_id,
        ]);
    }

    /**
     * El lienzo de la URL, ya validado: `['clave' => 'esquema:nulipara', 'variante' => 'nulipara', 'foto' => null]` o
     * `['clave' => 'foto:12', 'variante' => null, 'foto' => 12]`.
     *
     * @return array{clave:string, variante:?string, foto:?int}
     */
    private function lienzo(Request $request, Consulta $consulta, string $examen, string $lienzo): array
    {
        abort_unless(ImagenDelExamen::existe($examen), 404);

        if (ImagenDelExamen::esVariante($examen, $lienzo)) {
            return ['clave' => ImagenDelExamen::claveDelEsquema($lienzo), 'variante' => $lienzo, 'foto' => null];
        }

        $imagen = preg_match('/^foto-(\d+)$/', $lienzo, $coincidencia) === 1 ? (int) $coincidencia[1] : null;
        abort_if($imagen === null, 404);
        abort_if($this->foto($request, $consulta, $examen, $imagen) === null, 404);

        return ['clave' => ImagenDelExamen::claveDeLaFoto($imagen), 'variante' => null, 'foto' => $imagen];
    }

    /** La foto del examen, si es de esta consulta; `null` si no (es lo que hace el 404 del médico ajeno). */
    private function foto(Request $request, Consulta $consulta, string $examen, int $imagen): ?object
    {
        return $this->imagenes->deUnExamen($consulta, $examen)->firstWhere('id', $imagen);
    }

    /** La consulta del contexto (404 si es de otro médico). */
    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }
}
