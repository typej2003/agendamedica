<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearMotivoDeCita;
use App\Actions\Catalogos\EditarMotivoDeCita;
use App\Actions\Catalogos\QuitarMotivoDeCita;
use App\Clinica\Catalogos\MotivosDeCitaDelCatalogo;
use App\Clinica\Catalogos\Vistas\MotivosDeCitaVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Motivos de cita del catálogo** (WEB-8.1c): `motivo_cita` del médico del contexto en `/clinica/catalogos/motivos-cita`.
 * Listado paginado con buscador sin tildes, alta y edición en un diálogo (razón, **precio**, sede y la marca de varias
 * citas pendientes de WEB-2.17), el interruptor de esa marca y «quitar». El `w_agregar_motivo_cita` del escritorio, con
 * lo que el escritorio no tenía: el precio, la sede y la marca. Absorbió la pantalla `/clinica/agenda/motivos` de WEB-2.17.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403, pero **sí agenda** eligiendo entre
 * estos motivos) y **todo está acotado al `reg_medico` del contexto**, que sale del contexto de trabajo y nunca del
 * formulario: un motivo de otro médico responde 404. La regla no está acá: el alta es `CrearMotivoDeCita`, la edición
 * `EditarMotivoDeCita` —también la usa el interruptor— y la baja `QuitarMotivoDeCita`, que no borra lo que alguna cita tiene.
 */
class MotivoCitaCatalogoController extends Controller
{
    public function __construct(private MotivosDeCitaDelCatalogo $motivos)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.motivos-cita');

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->motivos->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.motivos-cita', [
            // Los motivos que la especialidad sugiere y el médico todavía no tiene (WEB-7.4.4f): se ofrecen, no se siembran solos.
            'sugeridos' => app(\App\Clinica\Catalogos\MotivosDeCitaSugeridos::class)->faltantes($regMedico, $contexto['specialty']?->slug),
            'vm' => new MotivosDeCitaVista(
            $this->motivos->pagina($regMedico, $buscar, (int) $request->query('page', 1), $base, $buscar === '' ? [] : ['buscar' => $buscar]),
            $buscar,
            $this->motivos->total($regMedico),
            $editando,
            $this->motivos->sedes($regMedico, $contexto['medico']->id),
            $base,
            [
                'guardar' => route('clinica.catalogos.motivos-cita.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.motivos-cita.quitar', $id),
                'alternar' => fn (int $id) => route('clinica.catalogos.motivos-cita.alternar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** **Agregar los motivos que sugiere la especialidad** (WEB-7.4.4f): «Control de niño sano» en pediatría. Idempotente. */
    public function agregarSugeridos(Request $request, \App\Actions\Catalogos\AgregarMotivosSugeridos $agregar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $agregados = $agregar->ejecutar($contexto['medico'], (string) $contexto['reg_medico'], $contexto['specialty']?->slug);

        return redirect()->route('clinica.catalogos.motivos-cita')->with('estado', $agregados === []
            ? 'Ya tienes los motivos que sugiere tu especialidad: no se agregó nada.'
            : 'Agregaste ' . implode(', ', array_map(fn ($r) => '«' . $r . '»', $agregados)) . ' a tus motivos de cita. Ponle el precio y la sede si quieres.');
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(Request $request, CrearMotivoDeCita $crear, EditarMotivoDeCita $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'id' => 'nullable|integer',
            // `nullable` y no `required`: el nombre vacío no es un error de formulario sino la regla de negocio de la Action,
            // y el mensaje tiene que ser el suyo.
            'razon' => 'nullable|string|max:300',
            'precio' => 'nullable|numeric|min:0',
            'office_id' => 'nullable|string|max:20',
            'admite_varias' => 'nullable|in:0,1',
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'id.integer' => 'No se pudo saber qué motivo se edita: vuelve a abrirlo.',
            'razon.max' => 'El nombre del motivo es demasiado largo.',
            'precio.numeric' => 'El precio debe ser un número.',
            'precio.min' => 'El precio no puede ser negativo.',
            'office_id.max' => 'Elige una sede de la lista.',
            'admite_varias.in' => 'La marca de varias citas no es válida.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ]);

        $fila = ($datos['id'] ?? null) !== null
            ? $this->motivos->encontrar($regMedico, (int) $datos['id'])
            : null;

        $entrada = [
            'razon' => $datos['razon'] ?? '',
            'precio' => $datos['precio'] ?? null,
            'office_id' => $datos['office_id'] ?? null,
            'admite_varias' => $datos['admite_varias'] ?? '0',
        ];

        try {
            if ($fila === null) {
                $creado = $crear->ejecutar($contexto['medico'], $regMedico, $entrada);
                $razon = trim((string) $creado->tipo_atencion);

                return redirect()
                    ->route('clinica.catalogos.motivos-cita', ['buscar' => $razon])
                    ->with('estado', 'Creaste «' . $razon . '» en tus motivos de cita (código ' . trim((string) $creado->codigo) . ').');
            }

            $resultado = $editar->ejecutar($fila, $contexto['medico'], $entrada);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.motivos-cita', $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null));
        $razon = trim((string) $resultado->fila->tipo_atencion);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $razon . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $razon . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $razon . '».');
    }

    /**
     * Marcar o desmarcar que el motivo admite **varias citas pendientes** del mismo paciente (WEB-2.17): es **una sola**
     * acción porque la marca es binaria —`null` y `false` significan lo mismo: no admite—. Pasa por la misma Action que la
     * edición, así que escribe sólo esa columna y deja su rastro. Desmarcar deja la columna en `null`, que es el estado de las
     * filas que nunca se marcaron y el que traen las del escritorio.
     */
    public function alternar(Request $request, int $id, EditarMotivoDeCita $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $motivo = $this->motivos->encontrar((string) $contexto['reg_medico'], $id);

        $marcado = ! (bool) $motivo->admite_varias_citas_pendientes;
        $resultado = $editar->ejecutar($motivo, $contexto['medico'], ['admite_varias' => $marcado]);

        $destino = redirect()->route('clinica.catalogos.motivos-cita', $this->vuelta(
            mb_substr(trim((string) $request->input('buscar', '')), 0, 100),
            $request->input('page'),
        ));
        $razon = trim((string) $motivo->tipo_atencion) !== '' ? trim((string) $motivo->tipo_atencion) : trim((string) $motivo->codigo);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $razon . '» más recientemente y no se pisó: vuelve a abrir la lista y revisa.');
        }

        return $destino->with('estado', $marcado
            ? 'Listo: con «' . $razon . '» el paciente puede tener varias citas pendientes.'
            : 'Listo: con «' . $razon . '» se vuelve a avisar de la cita pendiente.');
    }

    /**
     * Quitar un motivo. Si alguna cita lo tiene **no se borra** y el aviso dice cuántas son y desde cuándo; si no, se borra
     * (la pantalla pidió confirmación).
     */
    public function quitar(Request $request, int $id, QuitarMotivoDeCita $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fila = $this->motivos->encontrar((string) $contexto['reg_medico'], $id);

        $resultado = $quitar->ejecutar($fila, []);
        $destino = redirect()->route('clinica.catalogos.motivos-cita', $this->vuelta(
            mb_substr(trim((string) $request->input('buscar', '')), 0, 100),
            $request->input('page'),
        ));

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Déjalo en el catálogo: esas citas lo siguen mostrando.');
        }

        return $destino->with('estado', 'Quitaste «' . $resultado->nombre . '» de tus motivos de cita.');
    }

    /**
     * La pantalla de WEB-2.17 (`/clinica/agenda/motivos`) ya no existe: sus dos funciones —ver el catálogo y marcar la
     * excepción de citas múltiples— son ésta. El enlace viejo sigue funcionando y lleva a la nueva.
     */
    public function pantallaAnterior()
    {
        return redirect()->route('clinica.catalogos.motivos-cita', [], 301);
    }

    /** El error de la sede se pinta bajo el `<select>`, el del precio bajo el precio y el resto bajo el nombre. */
    private function campoDelError(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'sede de la lista') => 'office_id',
            str_contains($mensaje, 'precio') => 'precio',
            default => 'razon',
        };
    }

    /** A dónde vuelve la lista: la misma búsqueda y página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }
}
