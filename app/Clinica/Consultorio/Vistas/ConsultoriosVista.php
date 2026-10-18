<?php

namespace App\Clinica\Consultorio\Vistas;

use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Models\Medico;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Support\Collection;

/**
 * La pantalla de **consultorios del médico** (`/clinica/consultorios`): el listado y el formulario.
 *
 * Desde A-10 (revisión de Alexander) la fila del listado **también** lista los bloques de horario del
 * consultorio y deja agregar uno y quitar otro sin cambiar de pantalla: es lo que hace que la duración de la
 * cita y el horario se encuentren. La pantalla de horarios del consultorio sigue existiendo para cargar muchos,
 * y las reglas del solape no se repiten acá: las pone `GuardarBloqueHorario` detrás de las mismas rutas.
 */
final class ConsultoriosVista
{
    use ConValoresDeFormulario;

    /**
     * @param  Collection<int,Office>  $consultorios
     * @param  Collection<int,\App\Models\MedicalCenter>  $sedes  las sedes activas
     */
    public function __construct(
        private Collection $consultorios,
        private ?Office $editando,
        private bool $verInactivos,
        private Collection $sedes,
        private Medico $medico,
    ) {
    }

    private function url(array $query = []): string
    {
        return route('clinica.consultorios', array_merge($this->verInactivos ? ['inactivos' => 1] : [], $query));
    }

    public function subtitulo(): string
    {
        return 'Dónde atiende ' . trim(($this->medico->prefix ?? '') . ' ' . $this->medico->name . ' ' . $this->medico->lastname)
            . '. La modalidad y la duración son lo que la agenda usa para armar la jornada.';
    }

    public function urlVolver(): string
    {
        return route('clinica.inicio');
    }

    public function urlGuardar(): string
    {
        return route('clinica.consultorios.guardar');
    }

    public function urlCancelar(): string
    {
        return $this->url();
    }

    public function urlSedes(): string
    {
        return route('clinica.sedes');
    }

    public function verInactivos(): int
    {
        return $this->verInactivos ? 1 : 0;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function filtros(): array
    {
        return [
            ['texto' => 'Activos', 'url' => route('clinica.consultorios'), 'activa' => ! $this->verInactivos],
            ['texto' => 'Todos', 'url' => route('clinica.consultorios', ['inactivos' => 1]), 'activa' => $this->verInactivos],
        ];
    }

    /** Sin sedes no hay dónde colgar un consultorio: se avisa con el camino, no con un formulario inútil. */
    public function sinSedes(): bool
    {
        return $this->sedes->isEmpty();
    }

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el consultorio' : 'Nuevo consultorio';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar cambios' : 'Registrar el consultorio';
    }

    /** @return array<int,array{valor:int, texto:string, elegida:bool}> */
    public function opcionesDeSede(): array
    {
        $elegida = (int) $this->valor('medical_center_id');

        return $this->sedes->map(fn ($sede) => ['valor' => $sede->id, 'texto' => $sede->name, 'elegida' => $elegida === $sede->id])->all();
    }

    /** @return array<int,array{valor:string, texto:string, elegida:bool}> */
    public function opcionesDeModalidad(): array
    {
        $elegida = $this->valor('modalidad', Office::MODALIDAD_ORDEN);

        return collect(Office::modalidades())
            ->map(fn ($texto, $clave) => ['valor' => $clave, 'texto' => $texto, 'elegida' => $elegida === $clave])
            ->values()
            ->all();
    }

    public function duracionPorDefecto(): int
    {
        return Office::DURACION_CITA_POR_DEFECTO;
    }

    /**
     * Para qué sirve la duración de la cita, que es el otro dato que Alexander no encontraba (A-10).
     *
     * Dice lo que **de verdad** hace, sin prometer lo que no: la jornada y su tope salen de los bloques de
     * horario (`ArmadorDeAgenda` mide el cupo contra el bloque), no de este campo, que es el dato del
     * consultorio con el que se lee cuánto se le da a cada paciente.
     */
    public function ayudaDuracion(): string
    {
        return 'Lo que dura una cita en este consultorio. Se muestra acá y en la pantalla de horarios, y es el '
            . 'dato con el que se lee cada cuánto entra un paciente. El tope de pacientes de cada jornada lo '
            . 'pone el cupo de sus bloques de horario. Vacío = sin duración configurada.';
    }

    public function seSigueUsando(): bool
    {
        return (bool) $this->valor('activo', $this->editando?->activo ?? true);
    }

    public function estaVacio(): bool
    {
        return $this->consultorios->isEmpty();
    }

    public function textoVacio(): string
    {
        return 'No hay consultorios' . ($this->verInactivos ? '' : ' activos') . '.';
    }

    /**
     * Los días de la semana para el alta de un bloque, con el que quedó elegido si el POST de **este**
     * consultorio falló la validación (los otros formularios de la tabla nacen vacíos).
     *
     * @return array<int,array{valor:int, texto:string, elegido:bool}>
     */
    public function opcionesDeDia(int $consultorio): array
    {
        $elegido = $this->valoresDelBloque($consultorio)['dia_semana'];

        return collect(OfficeSchedule::DIAS)
            ->map(fn ($nombre, $numero) => [
                'valor'   => (int) $numero,
                'texto'   => $nombre,
                'elegido' => (string) $elegido === (string) $numero,
            ])
            ->values()
            ->all();
    }

    /**
     * Lo que quedó escrito en el alta de un bloque cuando el POST falló la validación.
     *
     * El alta de bloques es **un formulario por consultorio** y todos mandan los mismos nombres
     * (`dia_semana`, `hora_inicio`…), así que el `consultorio` oculto es lo que dice de cuál vino: sin esto,
     * un día mal elegido en el primero de la tabla aparecería escrito en todos. (La guía de UI pide no
     * borrar lo escrito ante un error.)
     *
     * @return array{dia_semana:string, hora_inicio:string, hora_fin:string, cupo:string}
     */
    public function valoresDelBloque(int $consultorio): array
    {
        $esDeEste = (string) old('consultorio') === (string) $consultorio;

        return [
            'dia_semana'  => $esDeEste ? (string) old('dia_semana', '') : '',
            'hora_inicio' => $esDeEste ? (string) old('hora_inicio', '') : '',
            'hora_fin'    => $esDeEste ? (string) old('hora_fin', '') : '',
            'cupo'        => $esDeEste ? (string) old('cupo', '') : '',
        ];
    }

    /**
     * El borrado de un bloque no borra nada clínico —las citas que ya caían ahí siguen existiendo—, pero
     * cambia la jornada con la que se mide el cupo: se pide confirmación explícita y se nombra el bloque.
     */
    public function confirmarQuitarBloque(OfficeSchedule $bloque): string
    {
        return '¿Quitar el bloque del ' . $bloque->nombreDelDia() . ' de ' . $bloque->rango()
            . '? Esas horas dejan de contarse como jornada; las citas que ya tenía no se borran.';
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        $modalidades = Office::modalidades();

        return $this->consultorios->map(function (Office $consultorio) use ($modalidades) {
            // Los bloques de horario se ven y se editan **acá** (A-10): antes el listado sólo tenía el
            // enlace a su pantalla y el médico no los encontraba. La pantalla aparte sigue existiendo
            // —para cargar muchos— y el enlace queda como atajo.
            $bloques = $consultorio->schedules
                ->sortBy(fn (OfficeSchedule $bloque) => [(int) $bloque->dia_semana, (string) $bloque->hora_inicio])
                ->map(fn (OfficeSchedule $bloque) => [
                    'id'          => (int) $bloque->id,
                    'dia'         => $bloque->nombreDelDia(),
                    'horario'     => $bloque->rango(),
                    'cupo'        => $bloque->cupo ? $bloque->cupo . ' pacientes' : null,
                    'urlEliminar' => route('clinica.consultorios.horarios.eliminar', [$consultorio, $bloque]),
                    'confirmar'   => $this->confirmarQuitarBloque($bloque),
                ])
                ->values()
                ->all();

            return [
                'id'          => (int) $consultorio->id,
                'sede'        => $consultorio->medicalCenter->name ?? 'Sede',
                'consultorio' => $consultorio->office_number,
                'modalidad'   => $consultorio->modalidad === Office::MODALIDAD_ORDEN
                    ? ['tono' => 'info', 'icono' => 'bi-people', 'texto' => $modalidades[$consultorio->modalidad] ?? $consultorio->modalidad]
                    : ['tono' => 'neutro', 'icono' => 'bi-clock', 'texto' => $modalidades[$consultorio->modalidad] ?? $consultorio->modalidad],
                'duracion'    => $consultorio->duracion_cita ? $consultorio->duracion_cita . ' min' : null,
                'bloques'     => $bloques,
                'opcionesDeDia' => $this->opcionesDeDia((int) $consultorio->id),
                'alta'        => $this->valoresDelBloque((int) $consultorio->id),
                'urlGuardarBloque' => route('clinica.consultorios.horarios.guardar', $consultorio),
                'estado'      => $consultorio->activo
                    ? ['tono' => 'exito', 'icono' => 'bi-check-circle', 'texto' => 'Activo']
                    : ['tono' => 'neutro', 'icono' => 'bi-slash-circle', 'texto' => 'Inactivo'],
                'urlHorarios' => route('clinica.consultorios.horarios', $consultorio),
                'urlEditar'   => $this->url(['editar' => $consultorio->id]),
                'urlActivar'  => route('clinica.consultorios.activar', $consultorio->id),
                'activar'     => $consultorio->activo
                    ? ['texto' => 'Desactivar', 'icono' => 'bi-slash-circle', 'peligro' => true]
                    : ['texto' => 'Activar', 'icono' => 'bi-check-circle', 'peligro' => false],
            ];
        })->all();
    }
}
