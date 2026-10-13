<?php

namespace App\Clinica\Consulta\Vistas;

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
    use ComparteElEditorDeRecipes;

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
        private ?PestanasDeRecipes $pestanas = null,
    ) {
        $this->recipes = $recipes;
    }

    /** ¿Es la vista del récipe de la pareja? (No: es la del paciente; la vista elige el parcial con esto.) */
    public function esDeLaPareja(): bool
    {
        return false;
    }

    /**
     * Las pestañas «Récipes del paciente» / «Récipe de la pareja» (WEB-5.1b), o `[]` si la sección no las
     * ofrece. Sólo se muestran con la lista: mientras se edita un récipe no se cambia de pestaña (se perdería
     * lo escrito).
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function pestanas(): array
    {
        return $this->pestanas === null || $this->esEditor() ? [] : $this->pestanas->opciones(PestanasDeRecipes::PACIENTE);
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
                // La vista previa para imprimir (WEB-5.2); vacía si la sección no ofrece imprimir.
                'urlImprimir' => $this->puedeImprimir() ? $this->urlImprimir($recipe->numero) : '',
                // El QR de verificación (WEB-5.6): su estado y la acción de anularlo o reactivarlo; `null` si no se ofrece.
                'verificacion' => $this->verificacionDe($recipe->numero),
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
     * Las filas del editor (de la base, o las del `old()` si el `POST` falló: ver el trait).
     *
     * @return array<int,array{clave:int, codigo:string, nombre:string, generico:?string, cantidad:string, indicaciones:string}>
     */
    private function filas(?object $recipe): array
    {
        return $this->filasDelEditor($recipe?->items);
    }
}
