<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarDibujoDeColposcopia;
use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\DibujoDeColposcopia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * **La colposcopía con imagen editable** (A-13): servir, guardar y quitar el trazo de cada variante del cuello
 * uterino (`nulipara` / `multipara`) y recordar su imagen base (`vector` / `escritorio`). La pantalla es la tarjeta
 * «Dibujo del cuello uterino» de la solapa de colposcopía; el lienzo es `public/js/clinica/lienzo.js` con su
 * inicialización en `colposcopia-lienzo.js`. La regla de escritura es la Action `GuardarDibujoDeColposcopia`.
 *
 * Como las mamas (WEB-11.10), el archivo vive en el disco privado `clinica` y sólo se sirve por esta ruta
 * autorizada: el 404 es del médico ajeno y el 403, de la secretaría (el grupo de rutas de la consulta).
 */
class ConsultaColposcopiaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function ver(Request $request, Consulta $consulta, string $variante)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(in_array($variante, DibujoDeColposcopia::VARIANTES, true), 404);

        $dibujo = DibujoDeColposcopia::where($this->clave($consulta, $variante))->first();
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
     * Guarda el trazo y/o la base. El `dibujo` es **opcional**: sin archivo se recuerda la base y se conserva el
     * trazo anterior (es lo que manda la pantalla al cambiar la imagen base).
     */
    public function guardar(Request $request, Consulta $consulta, string $variante, GuardarDibujoDeColposcopia $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(in_array($variante, DibujoDeColposcopia::VARIANTES, true), 404);

        $request->validate([
            'base' => ['nullable', Rule::in(DibujoDeColposcopia::BASES)],
            'dibujo' => ['nullable', 'file', 'max:' . ArchivosClinicos::MAXIMO_KB],
        ]);

        $base = (string) $request->input('base', DibujoDeColposcopia::BASE_POR_DEFECTO);

        try {
            $dibujo = $guardar->ejecutar($consulta, $variante, $base, $request->file('dibujo'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => 'No se pudo guardar el dibujo en el servidor.'], 500);
        }

        return response()->json([
            'guardado' => true,
            'base' => $dibujo->base,
            'dibujo' => $dibujo->archivo !== null,
        ]);
    }

    /** Quita el trazo de la variante y recuerda la base que quedó elegida. */
    public function quitar(Request $request, Consulta $consulta, string $variante, GuardarDibujoDeColposcopia $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(in_array($variante, DibujoDeColposcopia::VARIANTES, true), 404);

        $request->validate(['base' => ['nullable', Rule::in(DibujoDeColposcopia::BASES)]]);

        $guardar->quitar($consulta, $variante, $request->query('base'));

        return response()->json(['quitado' => true]);
    }

    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }

    /** @return array<string,mixed> */
    private function clave(Consulta $consulta, string $variante): array
    {
        return [
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'variante' => $variante,
        ];
    }
}
