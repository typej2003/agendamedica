<?php

namespace App\Clinica\Consultorio\Vistas;

use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Models\Medico;
use App\Models\Office;
use Illuminate\Support\Collection;

/** La pantalla de **consultorios del médico** (`/clinica/consultorios`): el listado y el formulario. */
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

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        $modalidades = Office::modalidades();

        return $this->consultorios->map(function (Office $consultorio) use ($modalidades) {
            $bloques = $consultorio->schedules->count();

            return [
                'sede'        => $consultorio->medicalCenter->name ?? 'Sede',
                'consultorio' => $consultorio->office_number,
                'modalidad'   => $consultorio->modalidad === Office::MODALIDAD_ORDEN
                    ? ['tono' => 'info', 'icono' => 'bi-people', 'texto' => $modalidades[$consultorio->modalidad] ?? $consultorio->modalidad]
                    : ['tono' => 'neutro', 'icono' => 'bi-clock', 'texto' => $modalidades[$consultorio->modalidad] ?? $consultorio->modalidad],
                'duracion'    => $consultorio->duracion_cita ? $consultorio->duracion_cita . ' min' : null,
                // Los bloques se cargan en su propia pantalla (WEB-2.8b.3): el número es el atajo, y sin
                // bloques el atajo invita a cargarlos.
                'bloques'     => $bloques === 0 ? null : $bloques . ($bloques === 1 ? ' bloque' : ' bloques'),
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
