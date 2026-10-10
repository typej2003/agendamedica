<?php

namespace App\Clinica\Consultorio\Vistas;

use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Models\MedicalCenter;
use Illuminate\Support\Collection;

/** La pantalla de **sedes** (`/clinica/sedes`): el listado y el formulario de alta/edición. */
final class SedesVista
{
    use ConValoresDeFormulario;

    /**
     * @param  Collection<int,MedicalCenter>  $sedes
     * @param  Collection<int,\App\Models\City>  $ciudades
     */
    public function __construct(
        private Collection $sedes,
        private ?MedicalCenter $editando,
        private bool $verTodas,
        private Collection $ciudades,
        private int $totalActivas,
    ) {
    }

    private function url(array $query = []): string
    {
        return route('clinica.sedes', array_merge($this->verTodas ? ['todas' => 1] : [], $query));
    }

    public function urlVolver(): string
    {
        return route('clinica.inicio');
    }

    public function urlGuardar(): string
    {
        return route('clinica.sedes.guardar');
    }

    public function urlCancelar(): string
    {
        return $this->url();
    }

    /** Lo que el POST necesita para volver a la misma lista (activas o todas). */
    public function verTodas(): int
    {
        return $this->verTodas ? 1 : 0;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function filtros(): array
    {
        return [
            ['texto' => 'Activas (' . $this->totalActivas . ')', 'url' => route('clinica.sedes'), 'activa' => ! $this->verTodas],
            ['texto' => 'Todas', 'url' => route('clinica.sedes', ['todas' => 1]), 'activa' => $this->verTodas],
        ];
    }

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar la sede' : 'Nueva sede';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar cambios' : 'Registrar la sede';
    }

    /**
     * El estado y el país no se preguntan: se derivan de la ciudad (los exige el esquema migrado y una
     * ciudad pertenece a un solo estado).
     *
     * @return array<int,array{valor:int, texto:string, elegida:bool}>
     */
    public function opcionesDeCiudad(): array
    {
        $elegida = (int) $this->valor('city_id');

        return $this->ciudades->map(fn ($ciudad) => [
            'valor'   => $ciudad->id,
            'texto'   => $ciudad->name . ' — ' . ($ciudad->state->name ?? ''),
            'elegida' => $elegida === $ciudad->id,
        ])->all();
    }

    public function seSigueUsando(): bool
    {
        return (bool) $this->valor('activo', $this->editando?->activo ?? true);
    }

    public function estaVacio(): bool
    {
        return $this->sedes->isEmpty();
    }

    /** @return array{texto:string, ayuda:string} */
    public function vacio(): array
    {
        return [
            'texto' => 'No hay sedes' . ($this->verTodas ? '' : ' activas') . '.',
            'ayuda' => 'Carga la primera en el formulario de arriba: sin sedes no se pueden crear consultorios.',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->sedes->map(fn (MedicalCenter $sede) => [
            'nombre'       => $sede->name,
            'direccion'    => $sede->address,
            'ciudad'       => $sede->city->name ?? null,
            'telefono'     => $sede->phone ?: null,
            'consultorios' => (int) $sede->consultorios_activos,
            'estado'       => $sede->activo
                ? ['tono' => 'exito', 'icono' => 'bi-check-circle', 'texto' => 'Activa']
                : ['tono' => 'neutro', 'icono' => 'bi-slash-circle', 'texto' => 'Inactiva'],
            'urlEditar'    => $this->url(['editar' => $sede->id]),
            'urlActivar'   => route('clinica.sedes.activar', $sede->id),
            // La baja es desactivar: la cita guarda `medical_center_id` y el historial tiene que poder
            // seguir leyendo el lugar.
            'activar'      => $sede->activo
                ? ['texto' => 'Desactivar', 'icono' => 'bi-slash-circle', 'peligro' => true]
                : ['texto' => 'Activar', 'icono' => 'bi-check-circle', 'peligro' => false],
        ])->all();
    }
}
