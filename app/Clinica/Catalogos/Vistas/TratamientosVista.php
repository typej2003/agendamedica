<?php

namespace App\Clinica\Catalogos\Vistas;

use Illuminate\Support\Collection;

/**
 * La pantalla de **tratamientos** del catálogo (`/clinica/catalogos/tratamientos`, WEB-8.1a): la lista de las
 * plantillas de récipe del médico, con su buscador por nombre. Alta y edición son el editor de otra pantalla
 * (`EditorDeTratamientoVista`): un tratamiento lleva una lista de medicamentos y no cabe en un diálogo.
 *
 * Como todo ViewModel de la web clínica no consulta la base: recibe los tratamientos ya leídos (con sus
 * medicamentos y el nombre ya resuelto) y las URLs que resolvió el controlador.
 */
final class TratamientosVista
{
    /** Cuántos medicamentos se nombran en la lista antes del «y N más». */
    private const NOMBRADOS = 3;

    /**
     * @param  Collection<int,object{id:int, codigo:string, nombre:string, items:Collection<int,object>}>  $tratamientos
     * @param  int  $total  cuántos tratamientos tiene el médico (sin búsqueda): decide el estado vacío
     * @param  array{base:string, nuevo:string, editar:\Closure, quitar:\Closure}  $urls  `editar` y `quitar` son
     *         funciones de id, que arma el controlador con `route()`
     */
    public function __construct(
        private Collection $tratamientos,
        private string $buscar,
        private int $total,
        private array $urls,
    ) {
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function hayBusqueda(): bool
    {
        return $this->buscar !== '';
    }

    public function urlBase(): string
    {
        return $this->urls['base'];
    }

    public function urlNuevo(): string
    {
        return $this->urls['nuevo'];
    }

    public function subtitulo(): string
    {
        $cuantos = $this->tratamientos->count();

        if ($this->hayBusqueda()) {
            return ($cuantos === 0 ? 'Sin resultados' : $cuantos . ($cuantos === 1 ? ' tratamiento' : ' tratamientos'))
                . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' tratamiento' : ' tratamientos')
            . '. Cada uno carga de una vez sus medicamentos al armar un récipe.';
    }

    public function estaVacio(): bool
    {
        return $this->tratamientos->isEmpty();
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún tratamiento coincide con «' . $this->buscar . '»',
                'texto' => 'Prueba con otra palabra del nombre. No importan las tildes.',
            ];
        }

        return [
            'icono' => 'bi-collection',
            'titulo' => 'Todavía no tienes tratamientos',
            'texto' => 'Un tratamiento junta los medicamentos que recetas juntos, con su cantidad e indicaciones, para cargarlos al armar un récipe de un solo toque.',
        ];
    }

    public function rango(): string
    {
        $cuantos = $this->tratamientos->count();

        return $cuantos === 0 ? '' : $cuantos . ($cuantos === 1 ? ' tratamiento' : ' tratamientos');
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->tratamientos->map(function (object $tratamiento) {
            $nombres = $tratamiento->items->pluck('nombre')->values();
            $cuantos = $nombres->count();
            $resto = $cuantos - self::NOMBRADOS;

            return [
                'id' => (int) $tratamiento->id,
                'nombre' => $tratamiento->nombre,
                'codigo' => $tratamiento->codigo,
                'cuantos' => $cuantos,
                'cantidad' => $cuantos === 0 ? 'Sin medicamentos' : $cuantos . ($cuantos === 1 ? ' medicamento' : ' medicamentos'),
                'resumen' => $cuantos === 0
                    ? null
                    : $nombres->take(self::NOMBRADOS)->implode(' · ') . ($resto > 0 ? ' · y ' . $resto . ' más' : ''),
                'urlEditar' => ($this->urls['editar'])((int) $tratamiento->id),
                'urlQuitar' => ($this->urls['quitar'])((int) $tratamiento->id),
                'avisoQuitar' => '¿Quitar el tratamiento «' . $tratamiento->nombre . '»? Se borra con '
                    . ($cuantos === 0 ? 'su lista vacía' : ($cuantos === 1 ? 'su medicamento' : 'sus ' . $cuantos . ' medicamentos'))
                    . '. Los récipes que ya hiciste con él no cambian y tu vademécum no se toca.',
            ];
        })->all();
    }
}
