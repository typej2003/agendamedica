<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Embarazo\GuardarGestaDelEmbarazo;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Embarazo\Vistas\PestanasDelEmbarazo;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **El control de embarazo de una consulta** (WEB-6.6, ítem 6 del panel del legado): guardar la gesta de la
 * pestaña *Prenatal*.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=embarazo&pestana=prenatal`); acá sólo se
 * escribe, que es la partición que la guía de la arquitectura pide. **El dato es del embarazo, no de la
 * consulta**: `pre_natal_observaciones` se llavea por `historia` + `gesta_clave`, así que el controlador
 * resuelve la consulta contra el contexto (una consulta de otro consultorio da **404**) y usa su
 * **`numhistoria`** para leer y escribir las gestas de esa historia.
 *
 * **Guardado explícito**, como la evaluación física y el estudio ecográfico: es un dato clínico y el médico
 * decide cuándo queda escrito. El aviso de "cambios sin guardar" y el bloqueo del navegador al salir los pone
 * `public/js/clinica/consulta.js` desde los `data-*` del formulario.
 *
 * La escritura —el rastro por columna en `sync_changes` y el last-write-wins que impide pisar una edición
 * más nueva del escritorio— es la Action `GuardarGestaDelEmbarazo`.
 */
class ConsultaEmbarazoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardarPrenatal(Request $request, Consulta $consulta, GuardarGestaDelEmbarazo $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $destino = $this->destino($consulta);
        $datos = $request->validate($this->reglas());

        // Sin `gesta` en el formulario, la Action crea la **siguiente** (*Nueva Gesta*).
        $clave = $request->filled('gesta') ? (int) $request->input('gesta') : null;

        try {
            $resultado = $guardar->ejecutar((string) $consulta->reg_medico, (int) $consulta->numhistoria, $datos, $clave, null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            // "Cada gesta necesita su FUR": no es el error de un campo, es que el embarazo entero se quedaría
            // sin sus dos cuentas, así que se avisa y no se guarda.
            return $destino->with('error', $excepcion->getMessage());
        }

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueadas)
                . ' más recientemente y no se pisó: vuelve a cargar la gesta y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'La gesta no tenía cambios.' : 'Gesta guardada.');
    }

    /** La vuelta a la sección, en la pestaña *Prenatal* (la única que existe hoy). */
    private function destino(Consulta $consulta)
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EMBARAZO,
            'pestana' => PestanasDelEmbarazo::PRENATAL,
        ]);
    }

    /**
     * Las reglas de los campos de la gesta. El tope de cada texto es el del DataWindow (`char(10)` en cuatro
     * de ellos) y `fur` es obligatoria —la Action también la exige, pero así el error llega al campo—.
     */
    private function reglas(): array
    {
        return [
            'fur' => 'required|date',
            'menarquia' => 'nullable|string|max:10',
            'gestas' => 'nullable|numeric|min:0|max:99',
            'parto' => 'nullable|string|max:10',
            'tipiaje' => 'nullable|string|max:10',
            'tipiaje_cony' => 'nullable|string|max:10',
            'observaciones' => 'nullable|string|max:32000',
            'gesta' => 'nullable|numeric|min:1',
        ];
    }

    /**
     * "«Menarquia»", "«Menarquia» y «Tipiaje»": nombra lo que no se pudo guardar con el rótulo de la
     * pantalla, no con el nombre de la columna.
     *
     * @param  array<int,string>  $columnas
     */
    private function listar(array $columnas): string
    {
        $etiquetas = [];
        foreach ($columnas as $columna) {
            $etiquetas[] = '«' . $this->etiqueta($columna) . '»';
        }

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
    }

    private function etiqueta(string $columna): string
    {
        foreach (\App\Clinica\Embarazo\Gesta::campos() as $campo) {
            if ($campo['columna'] === $columna) {
                return $campo['etiqueta'];
            }
        }

        return $columna;
    }
}
