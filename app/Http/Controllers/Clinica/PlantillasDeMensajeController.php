<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Configuracion\GuardarPlantillasDeMensaje;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Mensajes\PlantillasDelMedico;
use App\Clinica\Mensajes\Vistas\PlantillasDeMensajeVista;
use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Notificaciones\AnalisisDeUnaPlantilla;
use App\Notificaciones\PlantillasDeMensaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Mensajes a pacientes** (WEB-8.4): el editor de las plantillas de **citas** (`evolucion.plantilla_cita`, el recordatorio) y de
 * **cumpleaños** (`evolucion.plantilla_cumple`, el saludo), con sus etiquetas, el contador de caracteres y de SMS, los avisos y la
 * vista previa con datos de ejemplo.
 *
 * Sólo del médico (`role:Root|Medico`): la secretaría manda los mensajes pero no escribe las palabras del médico (403). Las plantillas
 * son **del médico y no del consultorio**: se leen y se guardan bajo su registro principal, la misma fila que edita el móvil
 * (`POST /app/configuracion`) y que baja `sync-app-data`. La escritura no está acá: es `GuardarPlantillasDeMensaje`, que delega en la
 * misma regla que el API del móvil. El análisis del texto (la vista previa, el largo y los avisos) es `AnalisisDeUnaPlantilla`, y es
 * **el mismo** que usa la vista previa en vivo (`vistaPrevia`): el JS no repite ninguna regla de etiquetas.
 *
 * Los textos de error van en español (trampa 38: el `locale` de la app es inglés).
 */
class PlantillasDeMensajeController extends Controller
{
    public function __construct(private PlantillasDelMedico $plantillas)
    {
    }

    /** La pantalla con los dos editores. */
    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $leido = $this->plantillas->de($contexto['medico']);

        $urls = [];
        foreach (PlantillasDeMensaje::slugs() as $slug) {
            $urls[$slug] = [
                'guardar' => route('clinica.mensajes.guardar', $slug),
                'restablecer' => route('clinica.mensajes.restablecer', $slug),
                'vistaPrevia' => route('clinica.mensajes.vista-previa', $slug),
            ];
        }

        return view('clinica.mensajes.index', ['vm' => new PlantillasDeMensajeVista(
            $leido['registro'],
            $leido['evolucion'],
            $contexto['reg_medico'] ?? null,
            $this->ejemplo($contexto),
            $urls,
            route('clinica.cumpleanos'),
        )]);
    }

    /** Guarda una plantilla (sólo lo que cambió). */
    public function guardar(Request $request, string $plantilla, GuardarPlantillasDeMensaje $guardar)
    {
        $definicion = PlantillasDeMensaje::de($plantilla);

        // El navegador manda los saltos de línea como `\r\n`; el largo que cuenta es el de `\n` (el del `maxlength` del campo).
        $request->merge(['texto' => $this->normalizar($request->input('texto'))]);

        $datos = $request->validate([
            'texto' => ['nullable', 'string', 'max:' . $definicion->maximo()],
        ], [
            'texto.string' => 'Escribe solo texto.',
            'texto.max' => 'La plantilla no puede tener más de ' . $definicion->maximo() . ' caracteres.',
        ]);

        try {
            $resultado = $guardar->ejecutar($this->medico($request), [$definicion->columna => $datos['texto'] ?? null], null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            return back()->withInput(['_plantilla' => $plantilla, 'texto' => $request->input('texto')])->with('error', $excepcion->getMessage());
        }

        if ($resultado->huboBloqueo()) {
            return $this->aMensajes($plantilla)->with('error', 'No se guardó: otro equipo (el teléfono o el escritorio) editó esta plantilla más recientemente. Recarga la página para ver lo último.');
        }

        if (! $resultado->huboCambios()) {
            return $this->aMensajes($plantilla)->with('estado', 'No había cambios que guardar en «' . $definicion->titulo . '».');
        }

        $vacia = trim((string) ($datos['texto'] ?? '')) === '';

        return $this->aMensajes($plantilla)->with('estado', $vacia
            ? 'Listo: «' . $definicion->titulo . '» quedó sin texto y sale el mensaje por defecto.'
            : 'Listo: se guardó «' . $definicion->titulo . '». Es la que sale desde ahora.');
    }

    /** «Restablecer»: borra el texto del médico y vuelve a salir el mensaje por defecto. */
    public function restablecer(Request $request, string $plantilla, GuardarPlantillasDeMensaje $guardar)
    {
        $definicion = PlantillasDeMensaje::de($plantilla);

        try {
            $resultado = $guardar->ejecutar($this->medico($request), [$definicion->columna => null], null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        if ($resultado->huboBloqueo()) {
            return $this->aMensajes($plantilla)->with('error', 'No se restableció: otro equipo (el teléfono o el escritorio) editó esta plantilla más recientemente. Recarga la página para ver lo último.');
        }

        return $this->aMensajes($plantilla)->with('estado', $resultado->huboCambios()
            ? 'Listo: «' . $definicion->titulo . '» volvió al mensaje por defecto.'
            : 'No había nada que restablecer: «' . $definicion->titulo . '» ya usa el mensaje por defecto.');
    }

    /**
     * La vista previa en vivo: el análisis del texto que se está escribiendo, **sin guardar nada**. Es JSON liviano para que el
     * script no repita la lógica de las etiquetas ni la cuenta de los SMS.
     */
    public function vistaPrevia(Request $request, string $plantilla): JsonResponse
    {
        $definicion = PlantillasDeMensaje::de($plantilla);

        $datos = $request->validate(['texto' => ['nullable', 'string', 'max:5000']], [
            'texto.string' => 'Escribe solo texto.',
            'texto.max' => 'El texto es demasiado largo.',
        ]);

        $analisis = AnalisisDeUnaPlantilla::de(
            $definicion,
            $this->normalizar($datos['texto'] ?? null),
            $definicion->datosDeEjemplo($this->ejemplo($request->attributes->get('contexto_clinico'))),
        );

        return response()->json($analisis + ['sms_frase' => PlantillasDeMensajeVista::fraseDeSms($analisis['sms'])]);
    }

    // ------------------------------------------------------------------------------------------ Adentro

    private function medico(Request $request): Medico
    {
        return $request->attributes->get('contexto_clinico')['medico'];
    }

    private function aMensajes(string $plantilla)
    {
        return redirect(route('clinica.mensajes') . '#plantilla-' . $plantilla);
    }

    private function normalizar(mixed $texto): ?string
    {
        return $texto === null ? null : str_replace("\r\n", "\n", (string) $texto);
    }

    /**
     * Los datos de ejemplo que comparten la pantalla y la vista previa: el médico del contexto, el consultorio y un día cercano.
     *
     * @return array{doctor:string, centro:string, fecha:string}
     */
    private function ejemplo(array $contexto): array
    {
        return [
            'doctor' => (string) ($contexto['medico']->nombreMostrar ?? 'Dr. Ejemplo'),
            'centro' => (string) ($contexto['office']?->medicalCenter?->name ?? 'Consultorio de ejemplo'),
            'fecha' => FechaDelConsultorio::hoy()->addDay()->format('d/m/Y'),
        ];
    }
}
