<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEcoAbdominal;
use App\Actions\Consulta\GuardarEcoCardiogramaFetal;
use App\Actions\Consulta\GuardarEcoDoppler;
use App\Actions\Consulta\GuardarEcoGinecologico;
use App\Actions\Consulta\GuardarEcoMamario;
use App\Actions\Consulta\GuardarEcoObstetrico;
use App\Actions\Consulta\GuardarEcoObstetricoSegundo;
use App\Actions\Consulta\GuardarEcoObstetricoTercero;
use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\MapaDelEstudio;
use App\Clinica\Consulta\RotulosDeValidacion;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Support\FechaDeFormulario;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **El estudio ecográfico de una consulta** (WEB-6.1, ítem 5 del panel del legado): guardar el eco pélvico, los
 * dos obstétricos (WEB-6.2), el Doppler perinatal y el ecocardiograma fetal (WEB-6.3), el mamario y el
 * abdominal (WEB-6.4): **los siete** del ítem.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=estudio&tipo=ginecologico`); acá sólo se
 * escribe, que es la partición que la guía de la arquitectura pide (controladores por responsabilidad).
 *
 * El tipo viene en la URL (`{tipo}`) para que cada estudio sume su rama **sin ruta nueva**; hoy existen los
 * siete de `TiposDeEstudio` y cualquier otro da **404**.
 *
 * **Guardado explícito**, como la evaluación física: es un dato clínico y el médico decide cuándo queda
 * escrito. El aviso de "cambios sin guardar" y el bloqueo del navegador al salir los pone
 * `public/js/clinica/consulta.js` desde los `data-*` del formulario.
 *
 * La escritura —el rastro por columna en `sync_changes` y el last-write-wins que impide pisar una edición
 * más nueva del escritorio o del teléfono— es la Action de cada estudio.
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
        GuardarEcoObstetricoSegundo $guardarObstetricoSegundo,
        GuardarEcoDoppler $guardarDoppler,
        GuardarEcoCardiogramaFetal $guardarFetal,
        GuardarEcoMamario $guardarMamario,
        GuardarEcoAbdominal $guardarAbdominal,
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
        $accion = match ($tipo) {
            TiposDeEstudio::FETAL => $guardarFetal,
            TiposDeEstudio::DOPPLER => $guardarDoppler,
            TiposDeEstudio::MAMARIO => $guardarMamario,
            TiposDeEstudio::ABDOMINAL => $guardarAbdominal,
            TiposDeEstudio::TERCERO => $guardarObstetricoTercero,
            TiposDeEstudio::SEGUNDO => $guardarObstetricoSegundo,
            TiposDeEstudio::OBSTETRICO => $guardarObstetrico,
            default => $guardar,
        };

        $destino = $this->destino($consulta, $tipo);

        // **Las fechas se pasan a `AAAA-MM-DD` antes de validar** (O-18 y O-19): la FUR se teclea
        // `dd/mm/aaaa` y su columna es `date`. La conversión vive en `FechaDeFormulario` —el único lugar
        // donde esa regla está escrita—; lo que no es una fecha se queda como vino y la validación lo
        // rechaza con su mensaje en español.
        $camposDeFecha = $this->camposDeFecha($tipo);
        $request->merge(FechaDeFormulario::normalizar($request->all(), array_keys($camposDeFecha)));

        try {
            // O-15: los mensajes salen en español (`lang/es/validation.php`) y el campo se nombra con el
            // **rótulo de la pantalla**, no con la columna: «El campo transverso del ovario derecho no puede
            // ser negativo», no «The ovarioderap must be at least 0.». El diccionario de rótulos sale del
            // mapa del dato del estudio que se está guardando.
            $datos = $request->validate(
                $this->reglasDe($tipo),
                $this->mensajesDeFecha($camposDeFecha),
                RotulosDeValidacion::deLosGrupos($this->gruposDe($tipo)),
            );
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
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueadas, $tipo)
                . ' más recientemente y no se pisó: vuelve a cargar el estudio y guárdalo de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? 'El eco no tenía cambios.'
            : match ($tipo) {
                TiposDeEstudio::FETAL => 'Ecocardiograma fetal guardado.',
                TiposDeEstudio::DOPPLER => 'Eco Doppler guardado.',
                TiposDeEstudio::MAMARIO => 'Eco mamario guardado.',
                TiposDeEstudio::ABDOMINAL => 'Eco abdominal guardado.',
                TiposDeEstudio::SEGUNDO, TiposDeEstudio::TERCERO, TiposDeEstudio::OBSTETRICO => 'Eco obstétrico guardado.',
                default => 'Eco pélvico guardado.',
            });
    }

    /**
     * Las reglas de validación del tipo: cada columna del **mapa del dato** de ese estudio, con el tope de su
     * control. Es lo que hace que un tipo nuevo no tenga que escribir sus reglas a mano.
     */
    private function reglasDe(string $tipo): array
    {
        $reglas = [];
        // El eco pélvico tiene sus propios topes numéricos (sus medidas son `decimal(5,2)` y sus volúmenes
        // `double`) y sus textos son más largos: es la diferencia que ya existía entre `reglas()` y
        // `reglasDelMapa()`. Ahora las dos salen de la misma lista de campos, así que una columna nueva se
        // valida sin escribirse dos veces.
        $pelvico = $tipo === TiposDeEstudio::GINECOLOGICO;

        foreach ($this->camposDe($tipo) as $campo) {
            $reglas[$campo['columna']] = match ($campo['tipo'] ?? 'texto') {
                // La columna es `date`: el formato se fija, no se acepta «lo que parezca una fecha».
                'fecha', 'fecha-tecleada' => FechaDeFormulario::REGLA,
                // `entero` es el campo con máscara `###` del escritorio: el navegador ya no deja escribir
                // decimales y el servidor lo vuelve a comprobar (`integer`, no `numeric`).
                'numero' => ($campo['entero'] ?? false)
                    ? 'nullable|integer|min:0|max:99999'
                    : ($pelvico
                        ? 'nullable|numeric|min:0|' . $this->topeNumerico($campo['columna'])
                        : 'nullable|numeric|min:0|max:99999'),
                'area' => 'nullable|string|max:32000',
                'opciones' => 'nullable|string|max:20',
                'texto' => $pelvico
                    ? 'nullable|string|max:80'
                    : 'nullable|string|max:' . (int) ($campo['tope'] ?? 20),
                default => 'nullable|string|max:' . ($pelvico ? 10 : (int) ($campo['tope'] ?? 20)),
            };
        }

        return $reglas;
    }

    /**
     * El **mapa por bloques** de ese tipo de estudio, o `null` para el eco pélvico, cuyo mapa es una lista
     * plana (`EcoGinecologico::campos()`). Es lo que comparten las reglas y las fechas que hay que convertir.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function mapaDe(string $tipo): ?array
    {
        return match ($tipo) {
            TiposDeEstudio::FETAL => EcoCardiogramaFetal::grupos(),
            TiposDeEstudio::DOPPLER => EcoDoppler::grupos(),
            TiposDeEstudio::MAMARIO => EcoMamario::grupos(),
            TiposDeEstudio::ABDOMINAL => EcoAbdominal::grupos(),
            TiposDeEstudio::OBSTETRICO => EcoObstetrico::grupos(),
            TiposDeEstudio::SEGUNDO, TiposDeEstudio::TERCERO => EcoObstetricoTercero::grupos(),
            default => null,
        };
    }

    /**
     * El mapa **por bloques** de ese estudio: es lo que necesitan los rótulos de los mensajes (O-15), que
     * agregan el bloque cuando la etiqueta se repite («transverso del ovario derecho»).
     *
     * @return array<int,array{titulo:string,campos:array<int,array<string,mixed>>}>
     */
    private function gruposDe(string $tipo): array
    {
        return MapaDelEstudio::grupos($tipo);
    }

    /**
     * Los campos del mapa de ese estudio, en una sola lista.
     *
     * @return array<int,array<string,mixed>>
     */
    private function camposDe(string $tipo): array
    {
        return MapaDelEstudio::campos($tipo);
    }

    /**
     * Las columnas de **fecha** de ese estudio, con su rótulo y si se teclean `dd/mm/aaaa` (la FUR) o con el
     * calendario del navegador (la fecha del estudio).
     *
     * @return array<string,array{etiqueta:string, tecleada:bool}>
     */
    private function camposDeFecha(string $tipo): array
    {
        $fechas = [];

        foreach ($this->camposDe($tipo) as $campo) {
            $control = $campo['tipo'] ?? 'texto';

            if ($control !== 'fecha' && $control !== 'fecha-tecleada') {
                continue;
            }

            $fechas[$campo['columna']] = [
                'etiqueta' => $campo['etiqueta'],
                'tecleada' => $control === 'fecha-tecleada',
            ];
        }

        return $fechas;
    }

    /**
     * Los mensajes de esas fechas, en español y con el rótulo de la pantalla (O-15): el de Laravel dice «The
     * fur does not match the format Y-m-d», que no le sirve a nadie.
     *
     * @param  array<string,array{etiqueta:string, tecleada:bool}>  $camposDeFecha
     * @return array<string,string>
     */
    private function mensajesDeFecha(array $camposDeFecha): array
    {
        $mensajes = [];

        foreach ($camposDeFecha as $columna => $campo) {
            $mensajes[$columna . '.date_format'] = FechaDeFormulario::mensaje($campo['etiqueta'], $campo['tecleada']);
        }

        return $mensajes;
    }

    /**
     * La vuelta a la sección, en el tipo que se estaba mirando.
     *
     * La excepción es el **eco abdominal que se abre desde Procedimientos** (gastroenterología pediátrica,
     * 2026-10-19): el formulario manda `volver = procedimientos` y la vuelta es a esa sección, en su pestaña.
     */
    private function destino(Consulta $consulta, string $tipo)
    {
        if (request()->input('volver') === 'procedimientos') {
            return redirect()->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_PROCEDIMIENTOS,
                'pestana' => 'ecoabdominal',
            ]);
        }

        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => $tipo,
        ]);
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
    private function listar(array $columnas, string $tipo): string
    {
        $etiquetas = [];
        foreach ($columnas as $columna) {
            $etiquetas[] = '«' . $this->etiquetaDe($columna, $tipo) . '»';
        }

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
    }

    /**
     * El rótulo de pantalla de una columna del estudio que se está guardando: el mapa del Doppler y el del
     * ecocardiograma fetal tienen sus propios rótulos y los demás estudios comparten los del pélvico, que es de
     * donde salían hasta ahora.
     */
    private function etiquetaDe(string $columna, string $tipo): string
    {
        return match ($tipo) {
            TiposDeEstudio::FETAL => EcoCardiogramaFetal::etiquetaDe($columna),
            TiposDeEstudio::DOPPLER => EcoDoppler::etiquetaDe($columna),
            TiposDeEstudio::MAMARIO => EcoMamario::etiquetaDe($columna),
            TiposDeEstudio::ABDOMINAL => EcoAbdominal::etiquetaDe($columna),
            default => EcoGinecologico::etiquetaDe($columna),
        };
    }
}
