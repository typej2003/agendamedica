<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\ArmarResumenDelExamen;
use App\Actions\Consulta\GuardarEvaluacionFisica;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\EvaluacionFisica;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Support\NumeroDeFormulario;
use Illuminate\Http\Request;

/**
 * **La evaluación física de una consulta** (WEB-4.3, ítem 4 del panel del legado): guardar el examen
 * general —antropometría y examen por aparatos— y el ginecológico.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=evaluacion`); acá sólo se escribe, que es
 * la partición que la guía de la arquitectura pide: controladores por responsabilidad (la enfermedad
 * actual de WEB-4.2 tiene el mismo par lectura/escritura).
 *
 * **Guardado explícito**, como la enfermedad actual: es un dato clínico y el médico decide cuándo queda
 * escrito. El aviso de "cambios sin guardar" y el bloqueo del navegador al salir son de
 * `public/js/clinica/consulta.js`.
 *
 * Las columnas viven en **dos** tablas (`consultas` y `examen_fisico_nuevo`); el mapa, con su fuente del
 * legado, está en `App\Clinica\Consulta\ExamenFisicoDeLaConsulta`. La escritura —el rastro por columna en
 * `sync_changes` y el guard last-write-wins— es la Action `GuardarEvaluacionFisica`.
 */
class ConsultaEvaluacionController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    /**
     * Las **columnas numéricas** del examen y su tope. De acá salen las dos cosas que tienen que coincidir:
     * la validación (`numeric` con su rango) y la conversión de la coma al punto decimal, que se hace antes
     * de validar (`App\Support\NumeroDeFormulario`).
     *
     * La tensión no está: es un `char(8)` de texto con la máscara `###/###` del escritorio y su formato de
     * pantalla (`120/80`) es también el que se guarda.
     */
    private const RANGOS = [
        'peso' => 500,
        'talla' => 3,
        'fc' => 400,
        'pp' => 200,
        'circcefalica' => 120,
        'circtoraxica' => 250,
        'circabdominal' => 250,
    ];

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarEvaluacionFisica $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        // El `{consulta}` de la ruta se vuelve a resolver contra el contexto: una consulta de otro
        // consultorio da 404 (misma vara que el shell y que las otras secciones).
        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // La pantalla teclea con coma (`1,62`) y la columna es `double` con punto: la diferencia se resuelve
        // **antes de validar** y en un solo lugar (`App\Support\NumeroDeFormulario`, la misma idea que
        // `FechaDeFormulario` para las fechas de O-18). Lo que no es un número queda como vino, para que la
        // validación lo rechace con su mensaje.
        $request->merge(NumeroDeFormulario::normalizar($request->all(), array_keys(self::RANGOS)));
        $datos = $request->validate($this->reglas());
        $resultado = $guardar->ejecutar($consulta, $datos, null, 'web');

        // El chip del que se guardó (examen físico o ginecológico, WEB-11.9): se vuelve al mismo.
        $parametros = ['seccion' => ConsultaShellVista::SECCION_EVALUACION];
        if ($request->filled('pestana') && PestanasDelExamen::existe((string) $request->input('pestana'))) {
            $parametros['pestana'] = (string) $request->input('pestana');
        }

        $destino = redirect()->route('clinica.consulta', array_merge([$consulta->id], $parametros));

        // El guard del sync puede rechazar columnas porque hay una edición **más nueva** sobre ellas (de
        // otro equipo, el escritorio o el teléfono). Se dice **cuáles**, en vez de mostrar un "Guardado"
        // falso de lo que no se guardó. No confundir con "no había cambios", que no es un problema.
        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueadas)
                . ' más recientemente y no se pisó: vuelve a cargar la evaluación física y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? 'El examen no tenía cambios.'
            : 'Examen guardado.');
    }

    /**
     * **«Actualizar»** (WEB-11.8): arma los resúmenes del examen físico y del ginecológico con lo que hay **en el formulario** (no hace
     * falta guardar antes) más la colposcopía y la citología de la consulta. No escribe nada: la pantalla pone el texto en los cuadros
     * y el médico lo edita; se guarda con el botón o el autoguardado.
     */
    public function resumen(Request $request, Consulta $consulta)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $request->merge(NumeroDeFormulario::normalizar($request->all(), array_keys(self::RANGOS)));
        $datos = $request->validate($this->reglas());
        $datos['colposcopia'] = $consulta->observaciones;
        $datos['citologia'] = $consulta->faringe;

        return response()->json([
            'fisico' => ArmarResumenDelExamen::fisico($datos),
            'ginecologico' => ArmarResumenDelExamen::ginecologico($datos),
        ]);
    }

    /**
     * Las reglas de las columnas que edita la web. El examen y el ginecológico son texto libre del legado
     * (`text`), así que sólo llevan un tope para no aceptar un cuerpo absurdo.
     *
     *  - Los **números** llevan rango: `peso` y `talla` son medidas humanas y las circunferencias se miden
     *    en centímetros (`talla` en metros, como el escritorio: `w_consulta_principal.srw:9167`).
     *  - La **tensión** es el texto con máscara `###/###` del escritorio (`:9045`), pero se acepta
     *    cualquier texto corto: los datos del legado traen `120/80`, `120-80` y hasta `/`.
     */
    private function reglas(): array
    {
        $reglas = [];

        foreach (self::RANGOS as $columna => $maximo) {
            $reglas[$columna] = 'nullable|numeric|min:0|max:' . $maximo;
        }

        foreach (array_keys(EvaluacionFisica::columnas()) as $columna) {
            if (! array_key_exists($columna, $reglas)) {
                $reglas[$columna] = 'nullable|string|max:4000';
            }
        }

        return $reglas;
    }

    /**
     * "«Peso»", "«Peso» y «Talla»", "«Peso», «Talla» y «Tensión arterial»": nombra lo que no se pudo
     * guardar con el rótulo de la pantalla, no con el nombre de la columna.
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

    /** El rótulo de pantalla de una columna, buscándolo en el mapa de campos. */
    private function etiqueta(string $columna): string
    {
        $campos = array_merge(
            ExamenFisicoDeLaConsulta::antropometria(),
            ExamenFisicoDeLaConsulta::general(),
            ExamenFisicoDeLaConsulta::ginecologico(),
        );

        foreach ($campos as $campo) {
            if ($campo['columna'] === $columna) {
                return $campo['etiqueta'];
            }
        }

        return $columna;
    }
}
