<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **La pestaña Referencia** (WEB-5.3a): las remisiones de esta consulta a otros médicos, cada una con su motivo, el catálogo de
 * colegas **a la vista** —con su buscador y su «Referir» por fila— y el alta rápida del médico que falta.
 *
 * Son **varias por consulta, una por médico**. Cada referencia se muestra como una tarjeta con los datos del médico
 * (especialidad, clínica, teléfono y dirección: lo que sale en la hoja) y su motivo editable, que se guarda con su botón. Si el
 * médico de una referencia que viene del escritorio ya no está en el catálogo, la tarjeta lo dice con su código en vez de
 * inventar un nombre.
 *
 * Como Motivos de consulta (O-29), el catálogo está **siempre a la vista** y **referir es inmediato**: el «Referir» de una fila
 * es un `POST` que crea la referencia en el acto (la escribe `GuardarReferenciaDeLaConsulta`, con su rastro `source = web`), y
 * «Quitar» también. El **motivo** de cada referencia sigue guardándose con su botón, que es el detalle. Sin base de datos.
 */
final class ReferenciasVista
{
    /**
     * @param  Collection<int,object{id:int, cedula:int, motivo:?string, medico:?object}>  $referencias  lo que devolvió `ReferenciasDeLaConsulta`
     * @param  LengthAwarePaginator<int,object>  $catalogo  la página de la libreta de colegas (`DoctoresDelMedico::pagina`)
     * @param  string  $buscar  lo escrito en el buscador del catálogo (`?buscar=`)
     * @param  string  $urlSeccion  la pestaña misma, sin el buscador (a donde vuelve «Limpiar»)
     * @param  array<string,string>  $especialidades  `código => nombre`, para el alta del médico que falta
     * @param  array<string,string>  $urls  `agregar`, `medicoNuevo`
     * @param  Closure  $urlGuardar  `fn(int $cedula): string`
     * @param  Closure  $urlEliminar  `fn(int $cedula): string`
     * @param  Closure  $urlImprimir  `fn(int $cedula): string`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     * @param  bool  $abrirAlta  si el diálogo del alta se abre solo (el POST del alta falló)
     */
    public function __construct(
        private Collection $referencias,
        private LengthAwarePaginator $catalogo,
        private string $buscar,
        private string $urlSeccion,
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
        return $this->urls['buscar'] ?? '';
    }

    /**
     * **El catálogo de colegas a la vista** (O-29): cada médico con sus datos y su «Referir», o la marca de que ya está
     * referido (el servidor tampoco crearía la fila dos veces).
     *
     * @return array<int,array{cedula:int, nombre:string, detalle:string, yaEsta:bool, urlAgregar:string}>
     */
    public function catalogo(): array
    {
        $referidos = $this->referencias
            ->map(fn (object $referencia) => (int) $referencia->cedula)
            ->all();

        return $this->catalogo->getCollection()->map(fn (object $medico) => [
            'cedula' => (int) $medico->cedula,
            'nombre' => (string) $medico->nombre,
            'detalle' => implode(' · ', array_filter([$medico->especialidad ?? null, $medico->clinica ?? null, $medico->telefono ?? null])),
            'yaEsta' => in_array((int) $medico->cedula, $referidos, true),
            'urlAgregar' => $this->urlAgregar(),
        ])->values()->all();
    }

    /** ¿El buscador encontró algún médico en el catálogo? */
    public function hayResultados(): bool
    {
        return $this->catalogo->count() > 0;
    }

    /** Los enlaces de página de la libreta (O-29), o null si no hay más de una página. */
    public function paginador(): ?LengthAwarePaginator
    {
        return $this->catalogo->hasPages() ? $this->catalogo : null;
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    /** La pestaña misma, sin el buscador: a donde va el filtro del catálogo. */
    public function urlDelFiltro(): string
    {
        return (string) strtok($this->urlSeccion, '?');
    }

    /** A donde vuelve «Limpiar». */
    public function urlSeccion(): string
    {
        return $this->urlSeccion;
    }

    /** La ayuda del catálogo, que es lo que hace entendible la pantalla: qué hace «Referir» y qué queda para el botón. */
    public function ayudaDelCatalogo(): string
    {
        if (! $this->hayResultados()) {
            return $this->buscar === ''
                ? 'Tu catálogo de médicos está vacío: agrega el primero con «Agregar un médico que no está».'
                : 'Ningún médico de tu catálogo coincide con «' . $this->buscar . '». Si no está, agrégalo.';
        }

        return 'Cada «Referir» crea la referencia al instante; escribe su motivo y guárdalo con el botón. Se refiere al paciente a varios médicos.';
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
        return 'El catálogo de colegas está a la derecha: refiere con un clic y después escribe el motivo. La tarjeta trae su '
            . 'especialidad, clínica y teléfono, que son los que salen en la hoja.';
    }

    public function sinReferencias(): array
    {
        return [
            'icono' => 'bi-person-up',
            'titulo' => 'Esta consulta todavía no refiere al paciente a nadie',
            'texto' => 'Refiere un médico desde tu catálogo, a la derecha. Si no está, agrégalo desde ahí mismo.',
        ];
    }
}
