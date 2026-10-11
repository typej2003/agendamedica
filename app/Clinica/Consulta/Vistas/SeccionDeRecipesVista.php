<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Support\FechaClinica;
use Closure;
use Illuminate\Support\Collection;

/**
 * **Los récipes de la consulta** (WEB-5.1, ítem 10 del panel del legado): lo que la pantalla muestra —los
 * récipes que la consulta ya tiene, el editor de uno (nuevo o existente) y las herramientas para llenarlo:
 * el buscador del vademécum, los tratamientos y el alta del medicamento que falta— ya resuelto.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe los récipes ya leídos por
 * `App\Clinica\Consulta\RecipesDeLaConsulta` y las URLs que resolvió el controlador, y devuelve textos,
 * banderas y filas listas. Se prueba sin base de datos (`tests/Unit/Clinica/`).
 *
 * Tres decisiones que toma acá y no la vista:
 *
 *  - **Dos modos en la misma sección**: la *lista* de los récipes de la consulta (con «Editar» y
 *    «Eliminar» y el botón de «Nuevo récipe») y el *editor* de **uno** (`?recipe=nuevo` o `?recipe=2`).
 *    Editar siempre es posible, en el lugar y sin límite de tiempo (decisión del usuario, 2026-10-11).
 *  - **El editor es de guardado explícito y trabaja sobre el récipe entero**: los medicamentos que se
 *    agregan o se quitan, las cantidades y las indicaciones viajan en un solo `POST`, igual que el
 *    escritorio guarda con su botón. El aviso de «cambios sin guardar» y el `beforeunload` los pone
 *    `consulta-recipes.js` desde los `data-*`.
 *  - **Si un `POST` falló la validación, el editor vuelve con lo escrito** (el `old()` del formulario),
 *    incluidos los medicamentos agregados y quitados y sus nombres (que viajan en un campo de sólo
 *    pantalla, nunca como dato): no se pierde nada (guía de UI §3).
 */
final class SeccionDeRecipesVista
{
    /** La cantidad máxima por medicamento que el formulario acepta (`recipes.cantidad` es entera). */
    public const CANTIDAD_MAXIMA = 999;

    /** El tope de las indicaciones de una fila (`recipes.indicaciones` es un `long varchar`). */
    public const LARGO_INDICACIONES = 2000;

    /** @var Collection<int,object> */
    private Collection $recipes;

    /**
     * @param  Collection<int,object>  $recipes  lo que devolvió `RecipesDeLaConsulta::deLaConsulta()`
     * @param  string|null  $editando  `null` = la lista; `'nuevo'` = el editor de un récipe nuevo; un número
     *                                 = el editor de ese récipe. El controlador ya comprobó que existe.
     * @param  string  $fechaDeLaConsulta  `AAAA-MM-DD`: la fecha de emisión por defecto de un récipe nuevo
     * @param  array<string,string>  $urls  `lista`, `nuevo`, `guardarNuevo`, `medicamentos`, `tratamientos`,
     *                                      `crearMedicamento`: las URLs de la sección (sin parámetros)
     * @param  Closure  $urlEditar  `fn(int $numero): string` — el editor de ese récipe
     * @param  Closure  $urlGuardar  `fn(int $numero): string` — a dónde postea el guardado de ese récipe
     * @param  Closure  $urlEliminar  `fn(int $numero): string` — a dónde va el `DELETE` de ese récipe
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación (`[]` en la primera carga)
     */
    public function __construct(
        Collection $recipes,
        private ?string $editando,
        private string $fechaDeLaConsulta,
        private array $urls,
        private Closure $urlEditar,
        private Closure $urlGuardar,
        private Closure $urlEliminar,
        private array $old = [],
    ) {
        $this->recipes = $recipes;
    }

    // ------------------------------------------------------------------------------------------ Modos

    /** ¿Se está editando un récipe (nuevo o existente) en vez de mirar la lista? */
    public function esEditor(): bool
    {
        return $this->editando !== null;
    }

    public function esNuevo(): bool
    {
        return $this->editando === 'nuevo';
    }

    /** El número del récipe que se edita, o `null` si es nuevo o si se mira la lista. */
    public function numero(): ?int
    {
        return $this->editando !== null && ctype_digit($this->editando) ? (int) $this->editando : null;
    }

    /** El estado de la sección como pastilla: cuántos récipes tiene la consulta. */
    public function estado(): array
    {
        $total = $this->recipes->count();

        if ($total === 0) {
            return [
                'tono' => 'neutro',
                'icono' => 'bi-dash-circle',
                'texto' => 'Sin récipes',
                'titulo' => 'Esta consulta todavía no tiene récipes',
            ];
        }

        return [
            'tono' => 'marca',
            'icono' => 'bi-prescription2',
            'texto' => $total . ($total === 1 ? ' récipe' : ' récipes'),
            'titulo' => 'Los récipes que tiene esta consulta',
        ];
    }

    public function ayuda(): string
    {
        return 'Los récipes son de esta consulta: puedes hacer varios, corregir cualquiera cuando quieras y '
            . 'eliminar el que sobre. Editar las indicaciones de un medicamento no cambia tu vademécum.';
    }

    public function urlLista(): string
    {
        return $this->urls['lista'];
    }

    public function urlNuevo(): string
    {
        return $this->urls['nuevo'];
    }

    // ------------------------------------------------------------------------------------------ Lista

    /** ¿La consulta todavía no tiene ningún récipe? Es el estado vacío de la lista. */
    public function estaVacia(): bool
    {
        return $this->recipes->isEmpty();
    }

    /**
     * Los récipes de la consulta, listos para pintar: el título, sus datos, los medicamentos con su
     * cantidad e indicaciones y las URLs de sus acciones.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recetas(): array
    {
        return $this->recipes->map(function (object $recipe) {
            $total = $recipe->items->count();

            return [
                'numero' => $recipe->numero,
                'titulo' => 'Récipe ' . $recipe->numero,
                'emision' => $recipe->fecha === null ? '—' : FechaClinica::formato($recipe->fecha),
                'vigencia' => $recipe->vigencia . ($recipe->vigencia === 1 ? ' día' : ' días'),
                'resumen' => $total === 0
                    ? 'Sin medicamentos'
                    : $total . ($total === 1 ? ' medicamento' : ' medicamentos'),
                'vacio' => $total === 0,
                'items' => $recipe->items->map(fn (object $item) => [
                    'cantidad' => $item->cantidad === null ? '—' : (string) $item->cantidad,
                    'nombre' => $item->nombre,
                    'generico' => $item->generico,
                    'indicaciones' => $item->indicaciones,
                    'avisoVademecum' => $item->enVademecum ? null : 'ya no está en tu vademécum',
                ])->all(),
                'urlEditar' => ($this->urlEditar)($recipe->numero),
                'urlEliminar' => ($this->urlEliminar)($recipe->numero),
                'avisoEliminar' => '¿Eliminar el récipe ' . $recipe->numero . ' de esta consulta? Se borran sus '
                    . $total . ($total === 1 ? ' medicamento' : ' medicamentos')
                    . ' y no se puede deshacer. Tu vademécum no se toca.',
            ];
        })->all();
    }

    // ----------------------------------------------------------------------------------------- Editor

    /**
     * El editor del récipe que se edita: título, fecha de emisión, a dónde postea y las filas (de la base,
     * o las del `old()` si el `POST` falló). `null` si se mira la lista.
     *
     * @return array<string,mixed>|null
     */
    public function editor(): ?array
    {
        if (! $this->esEditor()) {
            return null;
        }

        $recipe = $this->numero() === null
            ? null
            : $this->recipes->first(fn (object $recipe) => $recipe->numero === $this->numero());

        $fecha = $recipe?->fecha ?? $this->fechaDeLaConsulta;
        if (array_key_exists('fecha', $this->old) && is_string($this->old['fecha'])) {
            $fecha = $this->old['fecha'];
        }

        return [
            'titulo' => $this->esNuevo() ? 'Récipe nuevo' : 'Récipe ' . $this->numero(),
            'fecha' => $fecha,
            'vigencia' => ($recipe->vigencia ?? RecipesDeLaConsulta::VIGENCIA_EN_DIAS) . ' días',
            'urlGuardar' => $this->esNuevo() ? $this->urls['guardarNuevo'] : ($this->urlGuardar)($this->numero()),
            'filas' => $this->filas($recipe),
        ];
    }

    /**
     * Las filas del editor: cada una con su clave dentro del formulario (`items[k]`), el código, el nombre
     * que se ve, la cantidad y las indicaciones. Si el `POST` falló, salen del `old()` (con los nombres que
     * viajaron como campo de pantalla); si no, de la base.
     *
     * @return array<int,array{clave:int, codigo:string, nombre:string, generico:?string, cantidad:string, indicaciones:string}>
     */
    private function filas(?object $recipe): array
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

        if ($recipe === null) {
            return [];
        }

        return $recipe->items->values()->map(fn (object $item, int $clave) => [
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
        return self::CANTIDAD_MAXIMA;
    }

    public function largoIndicaciones(): int
    {
        return self::LARGO_INDICACIONES;
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
