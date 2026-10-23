<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Embarazo\GuardarControlDelEmbarazo;
use App\Actions\Embarazo\CrearGestaDelEmbarazo;
use App\Actions\Embarazo\GuardarGestaDelEmbarazo;
use App\Actions\Embarazo\GuardarLaboratorioDelEmbarazo;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Embarazo\GestasDeLaHistoria;
use App\Clinica\Embarazo\Vistas\PestanasDelEmbarazo;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PrenaExamenB;
use App\Support\FechaDeFormulario;
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
        // La FUR se pasa a `AAAA-MM-DD` en el único lugar donde esa regla está escrita (acepta también lo que
        // llegue tecleado `dd/mm/aaaa`): la columna `pre_natal_observaciones.fur` es `date`.
        $request->merge(FechaDeFormulario::normalizar($request->all(), ['fur']));

        $datos = $request->validate($this->reglas(), $this->mensajes());

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

    /**
     * **«Nueva gesta»** (WEB-11.15): sólo pregunta cuántos bebés son. La regla —la anterior tiene que estar cerrada— es de la Action.
     */
    public function crearGesta(Request $request, Consulta $consulta, CrearGestaDelEmbarazo $crear, GestasDeLaHistoria $gestas)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $datos = $request->validate(['bebes' => 'required|integer|min:1|max:' . CrearGestaDelEmbarazo::MAXIMO_DE_BEBES], [
            'bebes.*' => 'Elige cuántos bebés son (de 1 a ' . CrearGestaDelEmbarazo::MAXIMO_DE_BEBES . ').',
        ]);

        try {
            $clave = $crear->ejecutar((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $datos['bebes'], null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            return $this->destino($consulta)->with('error', $excepcion->getMessage());
        }

        // La Action completa la FUR sola si un estudio ecográfico de esta gesta ya la traía: el aviso lo dice
        // en vez de pedirla de nuevo (2026-10-22).
        $creada = $gestas->fila((string) $consulta->reg_medico, (int) $consulta->numhistoria, $clave);
        $aviso = $creada !== null && trim((string) ($creada->fur ?? '')) !== ''
            ? 'Gesta ' . $clave . ' creada con la FUR del estudio ecográfico.'
            : 'Gesta ' . $clave . ' creada. Ahora completa su FUR y guárdala.';

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO, 'pestana' => PestanasDelEmbarazo::PRENATAL, 'gesta' => $clave])
            ->with('estado', $aviso);
    }

    /**
     * La vuelta a la sección, en la pestaña que corresponda. En las pestañas **por fecha** (*Laboratorio* y
     * *Controles*) la vuelta lleva la `gesta` y la `fecha` de la fila que se acaba de guardar —su clave— para
     * caer en lo guardado y no en un formulario en blanco de «fecha nueva».
     */
    private function destino(Consulta $consulta, string $pestana = PestanasDelEmbarazo::PRENATAL, ?int $gesta = null, ?string $fecha = null)
    {
        $parametros = [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EMBARAZO,
            'pestana' => $pestana,
        ];

        if ($gesta !== null) {
            $parametros['gesta'] = $gesta;
        }

        if ($fecha !== null && $fecha !== '') {
            $parametros['fecha'] = $fecha;
        }

        return redirect()->route('clinica.consulta', $parametros);
    }

    /**
     * **El laboratorio de la gesta** (`prena_exames_b`): una fila por fecha de laboratorio.
     */
    public function guardarLaboratorio(Request $request, Consulta $consulta, GuardarLaboratorioDelEmbarazo $guardar)
    {
        return $this->guardarLaFecha($request, $consulta, $guardar, PestanasDelEmbarazo::LABORATORIO);
    }

    /**
     * **Los controles de la gesta** (`pre_natal_desarrollo_fino`): una fila por fecha de control.
     */
    public function guardarControl(Request $request, Consulta $consulta, GuardarControlDelEmbarazo $guardar)
    {
        return $this->guardarLaFecha($request, $consulta, $guardar, PestanasDelEmbarazo::CONTROLES);
    }

    /**
     * Las dos pestañas por fecha (laboratorio y controles) guardan igual: la misma validación, el mismo
     * `gesta` oculto, la misma vuelta. Sólo cambia la Action y a qué pestaña se vuelve.
     */
    private function guardarLaFecha(Request $request, Consulta $consulta, object $accion, string $pestana)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // La fecha se pasa a `AAAA-MM-DD` en el único lugar donde esa regla está escrita.
        $request->merge(FechaDeFormulario::normalizar($request->all(), ['fecha']));

        // La gesta viene oculta en el formulario: es parte de la clave (`historia` + `gesta_clave` + `fecha`).
        $gesta = (int) $request->input('gesta', 0);

        // La vuelta de un error va a **esta** gesta y a **esta** fecha: si volviera a la pestaña pelada, la
        // pantalla quedaría en la gesta en curso —otra— y el siguiente «Guardar» escribiría la fila en otro
        // embarazo, con los valores del que se estaba mirando.
        $destino = $this->destino($consulta, $pestana, $gesta > 0 ? $gesta : null, (string) $request->input('fecha', ''));

        $datos = $request->validate($this->reglasDeLaFecha(), [
            'fecha.required' => 'Indica la fecha: es parte de la clave de la fila.',
            'fecha.date_format' => FechaDeFormulario::mensaje('Fecha'),
        ]);

        if ($gesta < 1) {
            return $destino->with('error', 'Elige la gesta antes de guardar: el laboratorio y los controles son de una gesta.');
        }

        try {
            $resultado = $accion->ejecutar((string) $consulta->reg_medico, (int) $consulta->numhistoria, $gesta, $datos, null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            return $destino->with('error', $excepcion->getMessage());
        }

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueadas)
                . ' más recientemente y no se pisó: vuelve a cargar la fila y guárdala de nuevo.');
        }

        // La vuelta es a la fila guardada (`$destino` ya lleva su `gesta` y su `fecha`): sus dos datos son la
        // clave de lo que se acaba de escribir.
        return $destino->with('estado', $resultado->sinCambios() ? 'No había cambios.' : 'Guardado.');
    }

    /**
     * Las reglas de los campos de la gesta. El tope de cada texto es el del DataWindow (`char(10)` en cuatro
     * de ellos) y `fur` es obligatoria —la Action también la exige, pero así el error llega al campo—.
     *
     * ⚠️ **Tienen que estar las trece columnas** que el formulario manda (`GuardarGestaDelEmbarazo::COLUMNAS`):
     * `validate()` devuelve **sólo** lo declarado, así que una columna que falte acá no se guarda y, peor,
     * la Action la recibe vacía y **borra lo que tenía** (los partos de una gesta del escritorio, por
     * ejemplo). Las casillas `final` y `multiple` guardan `S`/`N`, como las escribe el escritorio, y
     * `cantidad` es el número de fetos cuando el embarazo es múltiple.
     */
    private function reglas(): array
    {
        return [
            'fur' => 'required|' . FechaDeFormulario::FORMATO,
            'menarquia' => 'nullable|string|max:10',
            'gestas' => 'nullable|numeric|min:0|max:99',
            'partos' => 'nullable|numeric|min:0|max:99',
            'cesarea' => 'nullable|numeric|min:0|max:99',
            'abortos' => 'nullable|numeric|min:0|max:99',
            // `otros` es un número en la tabla (`integer`) y en el DataWindow (`long`).
            'otros' => 'nullable|numeric|min:0|max:99',
            'parto' => 'nullable|string|max:10',
            'tipiaje' => 'nullable|string|max:10',
            'tipiaje_cony' => 'nullable|string|max:10',
            'final' => 'nullable|in:S,N',
            'multiple' => 'nullable|in:S,N',
            'cantidad' => 'nullable|numeric|min:0|max:' . CrearGestaDelEmbarazo::MAXIMO_DE_BEBES,
            'observaciones' => 'nullable|string|max:32000',
            'gesta' => 'nullable|numeric|min:1',
        ];
    }

    /**
     * Los mensajes de la gesta, en español (O-15): el de Laravel diría «The fur field is required».
     *
     * @return array<string,string>
     */
    private function mensajes(): array
    {
        return [
            'fur.required' => 'La FUR es obligatoria: de ella salen la edad gestacional y la FPP.',
            'fur.date_format' => FechaDeFormulario::mensaje('FUR'),
        ];
    }

    /**
     * Las reglas de las pestañas **por fecha** (*Laboratorio* y *Controles*): la fecha y la gesta son
     * obligatorias (son la clave junto con la historia) y el resto de los campos son los del mapa del dato
     * de las dos tablas. El tope de cada uno es el de su control —el de las columnas cortas del escritorio
     * (`char(5)`, `char(20)`) o el de un texto largo para las áreas—, y la Action es la que además normaliza
     * lo que no es número.
     */
    private function reglasDeLaFecha(): array
    {
        $reglas = ['fecha' => 'required|' . FechaDeFormulario::FORMATO, 'gesta' => 'required|numeric|min:1'];

        foreach (array_merge(PrenaExamenB::campos(), PreNatalDesarrolloFino::campos()) as $campo) {
            $reglas[$campo['columna']] = 'nullable|string|max:'
                . (($campo['tipo'] ?? 'texto') === 'area' ? 32000 : ($campo['max'] ?? 20));
        }

        return $reglas;
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
