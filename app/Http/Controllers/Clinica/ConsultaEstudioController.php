<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEcoGinecologico;
use App\Actions\Consulta\GuardarEcoObstetrico;
use App\Actions\Consulta\GuardarEcoObstetricoTercero;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **El estudio ecográfico de una consulta** (WEB-6.1, ítem 5 del panel del legado): guardar el eco pélvico.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=estudio&tipo=ginecologico`); acá sólo se
 * escribe, que es la partición que la guía de la arquitectura pide (controladores por responsabilidad).
 *
 * El tipo viene en la URL (`{tipo}`) para que los estudios que llegan después (el obstétrico de WEB-6.2,
 * el doppler y el ecocardiograma de WEB-6.3, los ultrasonidos de WEB-6.4) sumen su rama **sin ruta nueva**;
 * hoy el único que existe es el ginecológico (`TiposDeEstudio`), y cualquier otro da **404**.
 *
 * **Guardado explícito**, como la evaluación física: es un dato clínico y el médico decide cuándo queda
 * escrito. El aviso de "cambios sin guardar" y el bloqueo del navegador al salir los pone
 * `public/js/clinica/consulta.js` desde los `data-*` del formulario.
 *
 * La escritura —el rastro por columna en `sync_changes` y el last-write-wins que impide pisar una edición
 * más nueva del escritorio o del teléfono— es la Action `GuardarEcoGinecologico`.
 */
class ConsultaEstudioController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(
        Request $request,
        Consulta $consulta,
        string $tipo,
        GuardarEcoGinecologico $guardar,
        GuardarEcoObstetrico $guardarObstetrico,
        GuardarEcoObstetricoTercero $guardarObstetricoTercero,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');

        // El `{consulta}` de la ruta se vuelve a resolver contra el contexto: una consulta de otro
        // consultorio da 404 (misma vara que el shell y que las otras secciones).
        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        if (! TiposDeEstudio::existe($tipo)) {
            abort(404);
        }

        // Cada tipo de estudio tiene su **Action** y su **mapa del dato**, pero el guardado es el mismo
        // (una fila por consulta, sólo lo que cambió, con su rastro): lo único que cambia es qué columnas y
        // con qué reglas. Un tipo nuevo suma su acción acá y su mapa en `reglasDe()`.
        $tipo = TiposDeEstudio::normalizar($tipo);
        $esObstetrico = $tipo === TiposDeEstudio::OBSTETRICO;
        $esTercero = $tipo === TiposDeEstudio::TERCERO;
        $accion = $esTercero ? $guardarObstetricoTercero : ($esObstetrico ? $guardarObstetrico : $guardar);

        $destino = $this->destino($consulta, $tipo);

        try {
            $datos = $request->validate($this->reglasDe($tipo));
            $resultado = $accion->ejecutar($consulta, $datos, null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            // "El eco necesita algún dato": no es un error de validación de un campo, es que el formulario
            // entero venía vacío, así que se avisa y no se crea la fila.
            return $destino->with('error', $excepcion->getMessage());
        }

        // El guard del sync puede rechazar columnas porque hay una edición **más nueva** sobre ellas (de
        // otro equipo, el escritorio o el teléfono). Se dice **cuáles**, en vez de mostrar un "Guardado"
        // falso de lo que no se guardó. No confundir con "no había cambios", que no es un problema.
        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueadas)
                . ' más recientemente y no se pisó: vuelve a cargar el estudio y guárdalo de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? 'El eco no tenía cambios.'
            : ($esObstetrico ? 'Eco obstétrico guardado.' : 'Eco pélvico guardado.'));
    }

    /**
     * Las reglas de validación del tipo: cada columna del **mapa del dato** de ese estudio, con el tope de su
     * control. Es lo que hace que un tipo nuevo no tenga que escribir sus reglas a mano.
     */
    private function reglasDe(string $tipo): array
    {
        if ($tipo !== TiposDeEstudio::OBSTETRICO && $tipo !== TiposDeEstudio::TERCERO) {
            return $this->reglas();
        }

        $reglas = [];

        $grupos = $tipo === TiposDeEstudio::TERCERO ? EcoObstetricoTercero::grupos() : EcoObstetrico::grupos();

        foreach ($grupos as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $reglas[$campo['columna']] = match ($campo['tipo'] ?? 'texto') {
                    'fecha' => 'nullable|date',
                    'numero' => 'nullable|numeric|min:0|max:99999',
                    'area' => 'nullable|string|max:32000',
                    'opciones' => 'nullable|string|max:20',
                    default => 'nullable|string|max:20',
                };
            }
        }

        return $reglas;
    }

    /** La vuelta a la sección, en el tipo que se estaba mirando. */
    private function destino(Consulta $consulta, string $tipo)
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => $tipo,
        ]);
    }

    /**
     * Las reglas de las columnas que edita la web, armadas del **mapa del dato** (`EcoGinecologico`), para
     * que una columna nueva no se olvide de validar.
     *
     * Los topes de los números no son decorativos: las medidas son `decimal(5,2)` y los volúmenes `double`,
     * así que un valor fuera de rango lo rechaza la base con un error 500 en vez de un aviso al médico.
     * Los `text` largos llevan el tope del legado (`edit.limit=32000` en el DataWindow).
     */
    private function reglas(): array
    {
        $reglas = [];

        foreach (EcoGinecologico::campos() as $campo) {
            $reglas[$campo['columna']] = match ($campo['tipo']) {
                'numero' => 'nullable|numeric|min:0|' . $this->topeNumerico($campo['columna']),
                'fecha' => 'nullable|date',
                'area' => 'nullable|string|max:32000',
                'texto' => 'nullable|string|max:80',
                default => 'nullable|string|max:10',
            };
        }

        return $reglas;
    }

    /** Hasta dónde entra un número en su columna: los `double` aguantan más que los `decimal(5,2)`. */
    private function topeNumerico(string $columna): string
    {
        return in_array($columna, ['volumen_der', 'volumen_izq'], true) ? 'max:999999.99' : 'max:999.99';
    }

    /**
     * "«Posición»", "«Posición» y «Forma»", "«Posición», «Forma» y «Borde»": nombra lo que no se pudo
     * guardar con el rótulo de la pantalla, no con el nombre de la columna.
     *
     * @param  array<int,string>  $columnas
     */
    private function listar(array $columnas): string
    {
        $etiquetas = [];
        foreach ($columnas as $columna) {
            $etiquetas[] = '«' . EcoGinecologico::etiquetaDe($columna) . '»';
        }

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
    }
}
