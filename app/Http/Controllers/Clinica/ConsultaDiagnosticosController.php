<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\AgregarDiagnosticoAlCatalogo;
use App\Actions\Consulta\GuardarDiagnosticosDeLaConsulta;
use App\Actions\Consulta\ResultadoDeDiagnosticos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Los diagnósticos de una consulta** (WEB-4.5, ítem 8 del panel del legado): escribir el detalle, agregar
 * uno del catálogo, quitarlo y **crear el que falta**.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=diagnostico`); acá están las escrituras, que
 * son acciones y por eso viven en un controlador propio (la guía de la web parte los controladores por
 * responsabilidad, no por pantalla: los motivos, la enfermedad, la evaluación y los antecedentes hacen lo
 * mismo).
 *
 * **Guardado explícito**, como las otras secciones: el formulario manda las tres cosas de una vez —el
 * detalle de lo que ya está, lo que se tildó del catálogo y lo que el médico sacó de la lista— y un solo
 * botón las aplica. Es lo que el escritorio hace al apretar *Guardar*: su pestaña de diagnósticos también
 * deja el `dw_8.update()` para el `ue_guardar` de la ventana (`w_consulta_principal.srw:2709`), aunque sus
 * botones de *Pasar diag.* y *Eliminar* inserten y borren al instante.
 *
 * Dos cosas que este controlador no hace y está bien que no haga:
 *
 *  - **No sabe de la base de datos**: la regla es `App\Actions\Consulta\GuardarDiagnosticosDeLaConsulta` (en
 *    `Actions\Consulta` porque la fila de `diagnostico_paciente` **es de la consulta**: se llavea por
 *    `nrohistoria` + `nroconsulta` + `codediagnostico`) y el alta del catálogo es
 *    `AgregarDiagnosticoAlCatalogo`.
 *  - **No inventa el `reg_medico` ni la consulta**: salen de la consulta ya resuelta contra el contexto, así
 *    que una consulta de otro consultorio da **404** sin confirmar que exista.
 */
class ConsultaDiagnosticosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /** Guardar el formulario completo: el detalle editado, lo agregado y lo quitado. */
    public function guardar(Request $request, Consulta $consulta, GuardarDiagnosticosDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate($this->reglas());

        // `quitar` son los **id** de las filas que el médico marcó con el botón: la baja no es inmediata
        // —el botón aplica lo editado, lo agregado y lo quitado juntos—, así que el navegador los manda
        // como `hidden` al guardar (`consulta.js`). El formulario nunca deduce una baja de lo que no vino:
        // la pantalla no puede borrar lo que no mostró.
        //
        // Los `agregar` del buscador llegan por los `hidden` del formulario **y/o** por la query del
        // `action` (la deja `SeccionDeDiagnosticosVista::urlGuardar`): se juntan, porque una recarga de la
        // página se lleva los `hidden` pero no la query, y lo tildado no se puede perder al guardar. Se
        // aceptan las dos formas (un código suelto o una lista): así el POST no depende de cómo el
        // navegador arme el cuerpo.
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
            array_map(fn ($id) => (int) $id, (array) ($datos['quitar'] ?? [])),
            fn (int $id) => $id > 0,
        ));

        $resultado = $guardar->ejecutar(
            $consulta,
            (array) ($datos['filas'] ?? []),
            $agregar,
            $quitar,
            null,
            'web',
        );

        return $this->volver($consulta, $resultado);
    }

    /**
     * **Crear el diagnóstico que falta** en el catálogo (`w_nuevo_diagnostico` del escritorio): el código lo
     * da el servidor y la descripción se guarda en mayúsculas. Si ya existe uno igual, se usa el existente
     * y **no se duplica** (la misma regla del móvil con los motivos y de WEB-4.2).
     *
     * Es **online-only** (PLAN-WEB.md §3.15) y el diagnóstico nuevo queda **tildado** para agregarse a la
     * consulta con el mismo botón de guardar: la sección es de guardado explícito y el alta del catálogo no
     * puede ser lo único que escriba solo.
     */
    public function crear(
        Request $request,
        Consulta $consulta,
        AgregarDiagnosticoAlCatalogo $catalogo,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // `nullable` y no `required`, igual que el alta de motivos y de antecedentes: el texto vacío no
            // es un error de formulario, es la regla de negocio de la Action y el mensaje tiene que ser el suyo.
            'descripcion' => 'nullable|string|max:200',
        ]);

        try {
            [$diagnostico, $creado] = $catalogo->ejecutar(
                $contexto['medico'],
                $this->registrosDelMedico($contexto['medico']),
                (string) ($datos['descripcion'] ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            // La sección se vuelve a mostrar con lo escrito para que se pueda corregir (guía de UI §3: ante
            // un error no se borra lo escrito).
            return redirect()
                ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO])
                ->withInput()
                ->withErrors(['descripcion' => $e->getMessage()]);
        }

        $codigo = trim((string) $diagnostico->codediagnostico);
        $nombre = trim((string) $diagnostico->descripcion);

        // Lo que ya estaba tildado en el buscador viaja con el alta (el formulario lo manda como `hidden` y
        // la query también lo puede traer): crear un diagnóstico nuevo **no** puede perder los pendientes,
        // que son de la pantalla y no de la base. El que se acaba de crear (o el existente) entra último.
        $agregar = [];
        // Del **cuerpo** se lee con `input()` y no con `$datos`: `validate()` sólo devuelve lo que valida
        // (acá, la descripción) y los pendientes no son un campo del alta sino el estado del buscador.
        foreach ((array) $request->input('agregar', []) as $pendiente) {
            if (is_string($pendiente) && trim($pendiente) !== '') {
                $agregar[trim($pendiente)] = true;
            }
        }
        foreach ((array) $request->query('agregar', []) as $pendiente) {
            if (is_string($pendiente) && trim($pendiente) !== '') {
                $agregar[trim($pendiente)] = true;
            }
        }
        $agregar[$codigo] = true;
        $agregar = array_keys($agregar);

        return redirect()
            ->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO,
                'agregar' => $agregar,
            ])
            ->with('estado', ($creado
                ? 'Creado «' . $nombre . '» en tu catálogo'
                : '«' . $nombre . '» ya estaba en tu catálogo')
                . '; se agrega a la consulta cuando guardes.');
    }

    /**
     * Las reglas del formulario.
     *
     * `filas` llega por **id de `diagnostico_paciente`** (el detalle es la única columna editable) y
     * `quitar` por esos mismos ids. `agregar` es un arreglo de **códigos** del catálogo (los que el
     * buscador dejó tildados). El detalle lleva el tope de la columna (`varchar(100)`) para no aceptar un
     * cuerpo absurdo.
     */
    private function reglas(): array
    {
        return [
            'filas' => 'nullable|array|max:200',
            'filas.*.detalle' => 'nullable|string|max:' . GuardarDiagnosticosDeLaConsulta::LARGO_DETALLE,
            'agregar' => 'nullable',
            'agregar.*' => 'string|max:5',
            'quitar' => 'nullable|array|max:200',
            'quitar.*' => 'integer',
        ];
    }

    /**
     * La vuelta a la sección con el mensaje de lo que pasó. El orden de los avisos importa: si el sync
     * bloqueó algo, se dice eso primero (no se puede tapar con un "Guardado" que no fue).
     */
    private function volver(Consulta $consulta, ResultadoDeDiagnosticos $resultado)
    {
        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO,
        ]);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a cargar los diagnósticos y guárdalos de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'Los diagnósticos no tenían cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado));
    }

    /** "Agregado 1, quitado 2 y guardado 3": lo que pasó, con sus cuentas, no un "Operación exitosa". */
    private function resumen(ResultadoDeDiagnosticos $resultado): string
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

        return 'Diagnósticos actualizados: ' . implode(', ', $partes) . '.';
    }

    /**
     * "«DOLOR PELVICO»", "«A» y «B»": nombra lo que pasó con cada diagnóstico, no con su id.
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
