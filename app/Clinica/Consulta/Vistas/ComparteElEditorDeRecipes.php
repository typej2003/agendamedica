<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use Illuminate\Support\Collection;

/**
 * **Lo que el editor del récipe del paciente y el de la pareja tienen en común** (WEB-5.1b): la caja de
 * herramientas para llenarlo —el buscador del vademécum, los tratamientos y el alta del medicamento que
 * falta—, los topes de cada fila, los avisos y las filas del editor (de la base, o las del `old()` de un
 * `POST` que falló). Los dos ViewModels (`SeccionDeRecipesVista` y `SeccionDeRecipeDeLaParejaVista`) usan los
 * mismos parciales de Blade para eso, así que el texto y la regla de cada cosa viven **una sola vez**.
 *
 * Lo usa quien tiene `$urls` (`medicamentos`, `tratamientos` y `crearMedicamento`), `$old` (el `old()` del
 * formulario) y `editor()` (`null` si no hay editor, o un arreglo con `filas`). Sin base de datos.
 */
trait ComparteElEditorDeRecipes
{
    // ----------------------------------------------------------------------------------------- Filas

    /**
     * Las filas del editor: cada una con su clave dentro del formulario (`items[k]`), el código, el nombre
     * que se ve, la cantidad y las indicaciones. Si el `POST` falló, salen del `old()` (con los nombres que
     * viajaron como campo de pantalla); si no, de la base.
     *
     * @param  Collection<int,object>|null  $items  los medicamentos que el récipe tiene en la base
     * @return array<int,array{clave:int, codigo:string, nombre:string, generico:?string, cantidad:string, indicaciones:string}>
     */
    protected function filasDelEditor(?Collection $items): array
    {
        if (($this->old['editor'] ?? null) !== null) {
            $filas = [];
            $clave = 0;

            foreach ((array) ($this->old['items'] ?? []) as $item) {
                if (! is_array($item) || trim((string) ($item['codemedicina'] ?? '')) === '') {
                    continue;
                }

                $filas[] = [
                    'clave' => $clave++,
                    'codigo' => trim((string) $item['codemedicina']),
                    'nombre' => trim((string) ($item['nombre'] ?? '')) ?: trim((string) $item['codemedicina']),
                    'generico' => null,
                    'cantidad' => trim((string) ($item['cantidad'] ?? '')),
                    'indicaciones' => (string) ($item['indicaciones'] ?? ''),
                ];
            }

            return $filas;
        }

        if ($items === null) {
            return [];
        }

        return $items->values()->map(fn (object $item, int $clave) => [
            'clave' => $clave,
            'codigo' => $item->codigo,
            'nombre' => $item->nombre,
            'generico' => $item->generico,
            'cantidad' => $item->cantidad === null ? '' : (string) $item->cantidad,
            'indicaciones' => (string) ($item->indicaciones ?? ''),
        ])->all();
    }

    /** La clave de la siguiente fila que el script agrega (una por encima de las que ya hay). */
    public function siguienteClave(): int
    {
        $editor = $this->editor();

        return $editor === null ? 0 : count($editor['filas']);
    }

    public function estadoDelEditor(): array
    {
        $editor = $this->editor();
        $total = $editor === null ? 0 : count($editor['filas']);

        return [
            'tono' => $total === 0 ? 'neutro' : 'marca',
            'icono' => $total === 0 ? 'bi-dash-circle' : 'bi-capsule',
            'texto' => $total === 0 ? 'Sin medicamentos' : $total . ($total === 1 ? ' medicamento' : ' medicamentos'),
        ];
    }

    // --------------------------------------------------------------------------------- Herramientas

    public function urlMedicamentos(): string
    {
        return $this->urls['medicamentos'];
    }

    public function urlTratamientos(): string
    {
        return $this->urls['tratamientos'];
    }

    public function urlCrearMedicamento(): string
    {
        return $this->urls['crearMedicamento'];
    }

    public function cantidadMaxima(): int
    {
        return SeccionDeRecipesVista::CANTIDAD_MAXIMA;
    }

    public function largoIndicaciones(): int
    {
        return SeccionDeRecipesVista::LARGO_INDICACIONES;
    }

    public function largoNombre(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_NOMBRE;
    }

    public function largoPresentacion(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_PRESENTACION;
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el récipe. Si sales ahora, se pierden.';
    }

    public function avisoDeConexion(): string
    {
        return 'Crear un medicamento necesita conexión con el servidor: se guarda en tu vademécum, el mismo '
            . 'que usa el escritorio y el teléfono.';
    }

    /** La ayuda del buscador: qué se busca y cómo. */
    public function ayudaDelBuscador(): string
    {
        return 'Busca por nombre comercial o por principio activo (genérico), sin importar las tildes. '
            . 'Elige un medicamento para agregarlo con su indicación del vademécum; puedes editarla.';
    }

    public function ayudaDeTratamientos(): string
    {
        return 'Un tratamiento carga de una vez todos sus medicamentos, con su cantidad e indicaciones. '
            . 'Después puedes quitar o cambiar lo que quieras antes de guardar.';
    }

    /** El `placeholder` del cuadro de búsqueda en cada modo. */
    public function placeholderMedicamentos(): string
    {
        return 'Keforal, cefalexina, ácido fólico…';
    }

    public function placeholderTratamientos(): string
    {
        return 'Control prenatal, infección urinaria…';
    }
}
