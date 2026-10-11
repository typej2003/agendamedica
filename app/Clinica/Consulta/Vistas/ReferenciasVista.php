<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;
use Illuminate\Support\Collection;

/**
 * **La pestaña Referencia** (WEB-5.3a): las remisiones de esta consulta a otros médicos, cada una con su motivo, el
 * buscador del catálogo `doctores` para referir a uno más y el alta rápida del colega que falta.
 *
 * Son **varias por consulta, una por médico**. Cada referencia se muestra como una tarjeta con los datos del médico
 * (especialidad, clínica, teléfono y dirección: lo que sale en la hoja) y su motivo editable, que se guarda con su botón.
 * Si el médico de una referencia que viene del escritorio ya no está en el catálogo, la tarjeta lo dice con su código en
 * vez de inventar un nombre. Sin base de datos.
 */
final class ReferenciasVista
{
    /**
     * @param  Collection<int,object{id:int, cedula:int, motivo:?string, medico:?object}>  $referencias  lo que devolvió `ReferenciasDeLaConsulta`
     * @param  array<string,string>  $especialidades  `código => nombre`, para el alta del médico que falta
     * @param  array<string,string>  $urls  `buscar`, `agregar`, `medicoNuevo`
     * @param  Closure  $urlGuardar  `fn(int $cedula): string`
     * @param  Closure  $urlEliminar  `fn(int $cedula): string`
     * @param  Closure  $urlImprimir  `fn(int $cedula): string`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     * @param  bool  $abrirAlta  si el diálogo del alta se abre solo (el POST del alta falló)
     */
    public function __construct(
        private Collection $referencias,
        private array $especialidades,
        private array $urls,
        private Closure $urlGuardar,
        private Closure $urlEliminar,
        private Closure $urlImprimir,
        private array $old = [],
        private bool $abrirAlta = false,
    ) {
    }

    public function estaVacia(): bool
    {
        return $this->referencias->isEmpty();
    }

    public function total(): int
    {
        return $this->referencias->count();
    }

    /**
     * Las referencias listas para pintar. El motivo es el guardado o, si un POST de esa referencia falló, lo escrito.
     *
     * @return array<int,array<string,mixed>>
     */
    public function referencias(): array
    {
        return $this->referencias->map(function (object $referencia) {
            $medico = $referencia->medico;
            $cedula = $referencia->cedula;
            $oldMotivo = ($this->old['cedula'] ?? null) !== null && (int) $this->old['cedula'] === $cedula ? ($this->old['motivo'] ?? null) : null;

            return [
                'cedula' => $cedula,
                'nombre' => $medico->nombre ?? 'Médico ' . $cedula,
                'conocido' => $medico !== null,
                'especialidad' => $medico->especialidad ?? null,
                'clinica' => $medico->clinica ?? null,
                'telefono' => $medico->telefono ?? null,
                'direccion' => $medico->direccion ?? null,
                'motivo' => (string) ($oldMotivo ?? $referencia->motivo ?? ''),
                'urlGuardar' => ($this->urlGuardar)($cedula),
                'urlEliminar' => ($this->urlEliminar)($cedula),
                'urlImprimir' => ($this->urlImprimir)($cedula),
                'avisoEliminar' => '¿Quitar la referencia a ' . ($medico->nombre ?? 'este médico') . '? Se borra su motivo.',
            ];
        })->all();
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        $total = $this->total();

        return $total === 0
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin referencias', 'titulo' => 'Esta consulta no refiere al paciente a nadie']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => $total . ($total === 1 ? ' referencia' : ' referencias'), 'titulo' => 'Médicos a los que se refiere al paciente'];
    }

    /** @return array<string,string> */
    public function especialidades(): array
    {
        return $this->especialidades;
    }

    /** Sin catálogo de especialidades no se puede dar de alta un médico (`doctores.codeespecial` es obligatorio). */
    public function puedeCrearMedico(): bool
    {
        return $this->especialidades !== [];
    }

    public function urlBuscar(): string
    {
        return $this->urls['buscar'];
    }

    public function urlAgregar(): string
    {
        return $this->urls['agregar'];
    }

    public function urlMedicoNuevo(): string
    {
        return $this->urls['medicoNuevo'];
    }

    public function abrirAlta(): bool
    {
        return $this->abrirAlta;
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el motivo de una referencia. Si sales ahora, se pierden.';
    }

    public function ayuda(): string
    {
        return 'Busca al médico en tu catálogo y refiérelo: la tarjeta trae su especialidad, clínica y teléfono, que son '
            . 'los que salen en la hoja. Escribe el motivo y guárdalo. Puedes referir al paciente a varios médicos.';
    }

    public function sinReferencias(): array
    {
        return [
            'icono' => 'bi-person-up',
            'titulo' => 'Esta consulta todavía no refiere al paciente a nadie',
            'texto' => 'Busca un médico de tu catálogo arriba y refiérelo. Si no está, agrégalo desde ahí mismo.',
        ];
    }
}
