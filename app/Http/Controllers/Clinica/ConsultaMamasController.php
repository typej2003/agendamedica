<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarDibujoDeMama;
use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\DibujoDeMama;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * **Imágenes de mamas** (WEB-11.10): servir, guardar y quitar el dibujo de una mama de la consulta. La pantalla es el chip
 * «Imágenes de mamas» del examen; el lienzo es `public/js/clinica/mamas-lienzo.js`. La regla de escritura es la Action
 * `GuardarDibujoDeMama` (compartida con el móvil). El archivo está en el disco privado y sólo se sirve con esta ruta autorizada.
 */
class ConsultaMamasController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function ver(Request $request, Consulta $consulta, string $lado)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(in_array($lado, DibujoDeMama::LADOS, true), 404);

        $dibujo = DibujoDeMama::where([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'lado' => $lado,
        ])->first();
        abort_unless($dibujo && Storage::disk(ArchivosClinicos::DISCO)->exists($dibujo->archivo), 404);

        return Storage::disk(ArchivosClinicos::DISCO)->response($dibujo->archivo, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'image/png',
        ]);
    }

    public function guardar(Request $request, Consulta $consulta, string $lado, GuardarDibujoDeMama $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(in_array($lado, DibujoDeMama::LADOS, true), 404);

        $request->validate(['dibujo' => ['required', 'file', 'max:' . ArchivosClinicos::MAXIMO_KB]]);

        try {
            $guardar->ejecutar($consulta, $lado, $request->file('dibujo'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => 'No se pudo guardar el dibujo en el servidor.'], 500);
        }

        return response()->json(['guardado' => true]);
    }

    public function quitar(Request $request, Consulta $consulta, string $lado, GuardarDibujoDeMama $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);
        abort_unless(in_array($lado, DibujoDeMama::LADOS, true), 404);

        $guardar->quitar($consulta, $lado);

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
}
