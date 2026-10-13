<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\AgregarAntecedenteAlCatalogo;
use App\Actions\Pacientes\GuardarAntecedentes;
use App\Actions\Pacientes\ResultadoDeAntecedentes;
use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Los antecedentes del paciente en la consulta** (WEB-4.4, ítem 3 del panel del legado): escribir el
 * detalle, marcar la alerta, agregar uno del catálogo y quitarlo.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=antecedentes`); acá están las escrituras,
 * que son acciones y por eso viven en un controlador propio (la guía de la web parte los controladores por
 * responsabilidad, no por pantalla: la enfermedad actual y la evaluación física hacen lo mismo).
 *
 * **Guardado explícito**, como las otras secciones: el formulario manda las tres cosas de una vez —lo que
 * se editó, lo que se agregó y lo que se quitó— y un solo botón las aplica. Eso evita que un destildado
 * accidental se guarde al instante y que el médico tenga que apretar "Guardar" dos veces.
 *
 * Dos cosas que este controlador no hace y está bien que no haga:
 *
 *  - **No sabe de la base de datos**: la regla es `App\Actions\Pacientes\GuardarAntecedentes` (está en
 *    `Pacientes` porque **los antecedentes son del paciente**, no de la consulta; el porqué, con la cita
 *    del `.srw`, está en el encabezado de la Action).
 *  - **No inventa el `reg_medico` ni el número de historia**: salen de la consulta ya resuelta contra el
 *    contexto, así que una consulta de otro consultorio da **404** sin confirmar que exista.
 */
class ConsultaAntecedentesController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /** Guardar el formulario completo: lo editado, lo agregado y lo quitado. */
    public function guardar(Request $request, Consulta $consulta, GuardarAntecedentes $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate($this->reglas());

        // `quitar` son los códigos que el médico marcó con el botón de la fila: la baja **no** es
        // inmediata —el botón aplica lo editado, lo agregado y lo quitado juntos—, así que el navegador los
        // manda como `hidden` al guardar (`consulta.js`). El formulario nunca deduce una baja de lo que no
        // vino: la pantalla no puede borrar lo que no mostró.
        //
        // Los `agregar` del buscador llegan por los `hidden` del formulario **y/o** por la query del
        // `action` (la deja `SeccionDeAntecedentesCatalogoVista::urlGuardar`): se juntan, porque una recarga
        // de la página se lleva los `hidden` pero no la query, y lo tildado no se puede perder al guardar.
        // Se aceptan las dos formas (un código suelto o una lista de códigos): así el POST no depende de
        // cómo el navegador arme el cuerpo.
        $agregar = [];
        foreach ((array) ($datos['agregar'] ?? []) as $codigo) {
            if (is_string($codigo) && trim($codigo) !== '') {
                $agregar[trim($codigo)] = true;
            }
        }
        foreach ((array) $request->query('agregar', []) as $codigo) {
            if (is_string($codigo) && trim($codigo) !== '') {
                $agregar[trim($codigo)] = true;
            }
        }
        $agregar = array_keys($agregar);

        $quitar = array_values(array_filter(
            array_map(fn ($codigo) => trim((string) $codigo), (array) ($datos['quitar'] ?? [])),
            fn ($codigo) => $codigo !== '' && ! in_array($codigo, $agregar, true),
        ));

        $resultado = $guardar->ejecutar(
            (string) $consulta->reg_medico,
            (string) $consulta->numhistoria,
            (array) ($datos['filas'] ?? []),
            $agregar,
            $quitar,
            null,
            'web',
        );

        return $this->volver($consulta, $resultado);
    }

    /**
     * **Crear el antecedente que falta** en el catálogo (`w_nuevo_antecedente` del escritorio): el código
     * lo da el servidor, la descripción se guarda en mayúsculas y el tipo se elige del catálogo.
     *
     * Es **online-only**, como el alta de motivos (PLAN-WEB.md §3.15). El antecedente nuevo queda
     * **agregado al paciente** en el mismo paso, que es lo que el médico quiso al escribirlo; hacerlo en
     * dos pasos lo obligaría a buscarlo otra vez.
     */
    public function crear(
        Request $request,
        Consulta $consulta,
        AgregarAntecedenteAlCatalogo $catalogo,
        GuardarAntecedentes $guardar,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // `nullable` y no `required`, igual que el alta de motivos: el texto vacío no es un error de
            // formulario, es la regla de negocio de la Action y el mensaje tiene que ser el suyo.
            'descripcion' => 'nullable|string|max:200',
            'tipo' => 'nullable|string|max:2',
        ]);

        $destino = route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]);

        try {            [$antecedente, $creado] = $catalogo->ejecutar(
                $contexto['medico'],
                (string) ($datos['descripcion'] ?? ''),
                (string) ($datos['tipo'] ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            // El diálogo se vuelve a abrir para que lo escrito se pueda corregir (guía de UI §3: ante un
            // error no se borra lo escrito).
            return redirect()->to($destino)
                ->withInput()
                ->withErrors(['descripcion' => $e->getMessage()])
                ->with('crear_antecedente', true);
        }

        $resultado = $guardar->ejecutar(
            (string) $consulta->reg_medico,
            (string) $consulta->numhistoria,
            [],
            [$antecedente->codigo],
            [],
            null,
            'web',
        );

        $mensaje = $resultado->agregados === []
            // El código ya estaba en el paciente: la Action no lo duplica. Pasa si el médico crea el
            // antecedente que ya tenía cargado (el buscador no lo ofrece, el diálogo sí puede).
            ? '«' . $antecedente->nombre . '» ' . ($creado ? 'quedó en tu catálogo' : 'ya estaba en tu catálogo')
                . '; el paciente ya lo tenía cargado.'
            : 'Creado «' . $antecedente->nombre . '» en tu catálogo y agregado a los antecedentes del paciente.';

        return redirect()->to($destino)->with('estado', $mensaje);
    }

    /**
     * El buscador en vivo del catálogo (`Accept: application/json`): filtra sin distinguir tildes ni
     * mayúsculas, un antecedente por nombre, y dice cuáles ya tiene el paciente.
     */
    public function buscar(Request $request, Consulta $consulta, AntecedentesDelPaciente $antecedentes)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));
        $tipo = trim((string) $request->query('tipo', ''));

        $delPaciente = $antecedentes->delPaciente($contexto['reg_medico'], (string) $consulta->numhistoria);
        $codigos = $delPaciente->pluck('codigo')->all();

        $resultados = $antecedentes->catalogo($contexto['reg_medico'], $buscar, $tipo)
            ->map(fn ($antecedente) => [
                'codigo' => (string) $antecedente->codigo,
                'descripcion' => (string) $antecedente->nombre,
                'tipo' => $antecedente->tipoNombre,
                'yaEsta' => in_array((string) $antecedente->codigo, $codigos, true),
            ])
            ->values();

        return response()->json([
            'antecedentes' => $resultados,
            'existe' => $buscar !== '' && $antecedentes->existeEnElCatalogo($contexto['reg_medico'], $buscar),
            'buscar' => $buscar,
        ]);
    }

    /**
     * Las reglas del formulario.
     *
     * `filas` llega por **id de `antece_paciente`**; `agregar` es un arreglo de **códigos** del catálogo
     * (los que el buscador dejó tildados más los que la lista ya tenía) y `quitar` los códigos que el
     * médico sacó con el botón de la fila. El detalle lleva el tope de la columna (`text`) para no aceptar
     * un cuerpo absurdo, y la alerta los dos valores del DataWindow del escritorio (`A` encendida, `B`
     * apagada).
     */
    private function reglas(): array
    {
        return [
            'filas' => 'nullable|array|max:200',
            'filas.*.detalle' => 'nullable|string|max:' . GuardarAntecedentes::LARGO_DETALLE,
            'filas.*.alerta' => 'nullable|in:A,B',
            'agregar' => 'nullable',
            'agregar.*' => 'string|max:4',
            'quitar' => 'nullable|array|max:200',
            'quitar.*' => 'string|max:4',
        ];
    }

    /**
     * La vuelta a la sección con el mensaje de lo que pasó. El orden de los avisos importa: si el sync
     * bloqueó algo, se dice eso primero (no se puede tapar con un "Guardado" que no fue).
     */
    private function volver(Consulta $consulta, ResultadoDeAntecedentes $resultado)
    {
        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES,
        ]);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a cargar los antecedentes y guárdalos de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'Los antecedentes no tenían cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado));
    }

    /** "Agregados 2, quitado 1 y guardados 3": lo que pasó, con sus cuentas, no un "Operación exitosa". */
    private function resumen(ResultadoDeAntecedentes $resultado): string
    {
        $partes = [];

        if ($resultado->agregados !== []) {
            $partes[] = 'agregado' . (count($resultado->agregados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->agregados);
        }
        if ($resultado->quitados !== []) {
            $partes[] = 'quitado' . (count($resultado->quitados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->quitados);
        }
        if ($resultado->guardados !== []) {
            $partes[] = 'guardado' . (count($resultado->guardados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->guardados);
        }

        return 'Antecedentes actualizados: ' . implode(', ', $partes) . '.';
    }

    /**
     * "«Hipertensión»", "«Hipertensión» y «Asma»", "«A», «B» y «C»": nombra lo que pasó con cada
     * antecedente, no con su id.
     *
     * @param  array<int,string>  $nombres
     */
    private function listar(array $nombres): string
    {
        $etiquetas = array_map(fn ($nombre) => '«' . $nombre . '»', $nombres);

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
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
