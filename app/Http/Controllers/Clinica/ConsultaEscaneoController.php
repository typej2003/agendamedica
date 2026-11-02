<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Escaneo\HojaEscaneada;
use App\Clinica\Escaneo\LecturaFallida;
use App\Clinica\Escaneo\LectorDeHojas;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * **Escanear la hoja de resultados** (WEB-13.15): `POST /clinica/consulta/{consulta}/examen/{solapa}/escanear`.
 *
 * Recibe la foto o el PDF (`hoja`), lo manda a leer y devuelve **el texto** en JSON para que `escanear-hoja.js` lo
 * ponga en el campo de la solapa. **No escribe nada**: ni la consulta, ni el archivo, ni `sync_changes`. El médico
 * revisa y corrige el texto, y lo guarda con el guardado de siempre (el de `ConsultaTextoDelExamenController`, con
 * su rastro). La regla de O-12 —«la lectura propone, el médico confirma»— es eso.
 *
 * Lo del HTTP: de quién es la consulta (el 404 del consultorio ajeno, igual que el guardado), qué solapa es (la ruta
 * sólo acepta las escaneables), el tipo **real** del archivo y su tope, y qué se le contesta al médico. El log deja
 * que hubo una lectura (consulta y usuario), nunca su contenido.
 *
 * **Dos entradas, una sola lectura** (O-49): las solapas de `TextosDelExamen` (ginecología y pediatría) y la sección
 * propia del internista *Resultados de exámenes*, que llega por su ruta con `leerResultados()`. Lo único que cambia
 * es la etiqueta que queda en el log; el tope, el lector y la respuesta son los mismos.
 */
class ConsultaEscaneoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    /** La etiqueta con la que la sección del internista (O-49) aparece en el log de la lectura. */
    public const SOLAPA_DEL_INTERNISTA = 'resultados-examenes';

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /**
     * **La lectura de la hoja de la sección del internista** (O-49): su ruta no lleva `{solapa}`, así que la etiqueta
     * del log se fija acá y el resto es la misma lectura.
     */
    public function leerResultados(Request $request, Consulta $consulta, LectorDeHojas $lector): JsonResponse
    {
        return $this->leer($request, $consulta, self::SOLAPA_DEL_INTERNISTA, $lector);
    }

    public function leer(Request $request, Consulta $consulta, string $solapa, LectorDeHojas $lector): JsonResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $request->validate(
            ['hoja' => 'required|file|max:' . HojaEscaneada::MAXIMO_KB],
            [
                'hoja.required' => 'Elige la foto o el PDF de la hoja.',
                'hoja.file' => 'Elige la foto o el PDF de la hoja.',
                'hoja.uploaded' => 'El archivo no llegó completo. Prueba con uno de menos de ' . (int) (HojaEscaneada::MAXIMO_KB / 1024) . ' MB.',
                'hoja.max' => 'El archivo pasa de ' . (int) (HojaEscaneada::MAXIMO_KB / 1024) . ' MB.',
            ],
        );

        $contenido = (string) file_get_contents($request->file('hoja')->getRealPath());
        $mime = HojaEscaneada::tipo($contenido);

        if ($mime === null) {
            return response()->json([
                'message' => 'Ese archivo no es una foto (JPG, PNG, WEBP) ni un PDF.',
                'errors' => ['hoja' => ['Ese archivo no es una foto (JPG, PNG, WEBP) ni un PDF.']],
            ], 422);
        }

        try {
            $texto = $lector->leer(...HojaEscaneada::paraLeer($contenido, $mime));
        } catch (LecturaFallida $e) {
            return response()->json(['message' => $e->getMessage(), 'motivo' => $e->motivo], $e->estadoHttp());
        }

        Log::info('Lectura de hoja escaneada', [
            'consulta_id' => $consulta->id,
            'solapa' => $solapa,
            'user_id' => $request->user()?->id,
            'tipo' => $mime,
        ]);

        return response()->json([
            'texto' => $texto,
            'aviso' => 'Revisa el texto leído contra la hoja antes de guardar: es una lectura automática.',
        ]);
    }
}
