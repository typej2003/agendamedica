<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Consulta\GuardarRecipeDeLaPareja;

/**
 * **El récipe de la pareja de la consulta** (WEB-5.1b, dentro del ítem 10 del panel del legado): lo que la
 * pantalla muestra —los datos de la pareja, los medicamentos que se le recetan y las herramientas para
 * llenarlos— ya resuelto.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe el récipe ya leído por
 * `App\Clinica\Consulta\RecipeDeLaPareja` y las URLs que resolvió el controlador. Se prueba sin base de datos.
 *
 * Tres decisiones que toma acá y no la vista:
 *
 *  - **Es siempre el editor**: la pareja es **una por consulta** (no hay lista de récipes) y se corrige en el
 *    lugar sin límite de tiempo, así que la pantalla es el formulario —vacío si todavía no hay, con lo guardado
 *    si ya hay— y no una vista de lectura aparte (decisión del usuario, 2026-10-11).
 *  - **El guardado es del récipe entero**: la cédula y el nombre de la pareja y todos sus medicamentos viajan en
 *    un solo `POST`, como el del paciente. El aviso de «cambios sin guardar» y el `beforeunload` los pone
 *    `consulta-recipes.js` desde los `data-*` (la cédula y el nombre cuentan: `data-recipe-extra`).
 *  - **Si un `POST` falló, el editor vuelve con lo escrito** (el `old()`), pareja incluida.
 *
 * Comparte con `SeccionDeRecipesVista` la caja de herramientas y las filas (`ComparteElEditorDeRecipes`).
 */
final class SeccionDeRecipeDeLaParejaVista
{
    use ComparteElEditorDeRecipes;

    /**
     * @param  object{cedula:string, nombre:string, items:\Illuminate\Support\Collection<int,object>}|null  $pareja
     *         lo que devolvió `RecipeDeLaPareja::de()`; `null` si la consulta todavía no tiene
     * @param  array<string,string>  $urls  `guardar`, `eliminar`, `medicamentos`, `tratamientos`,
     *                                      `crearMedicamento`: las URLs de la sección
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación (`[]` en la primera carga)
     */
    public function __construct(
        private ?object $pareja,
        private array $urls,
        private PestanasDeRecipes $pestanas,
        private array $old = [],
    ) {
    }

    /** Es la vista de la pareja (la vista elige el parcial con esto). */
    public function esDeLaPareja(): bool
    {
        return true;
    }

    /** ¿La consulta ya tiene récipe de la pareja guardado? Decide si se ofrece eliminarlo. */
    public function existe(): bool
    {
        return $this->pareja !== null;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function pestanas(): array
    {
        return $this->pestanas->opciones(PestanasDeRecipes::PAREJA);
    }

    // ----------------------------------------------------------------------------------------- Textos

    public function titulo(): string
    {
        return 'Récipe de la pareja';
    }

    public function ayuda(): string
    {
        return 'Cada consulta tiene un solo récipe de la pareja (por ejemplo, el tratamiento de la pareja en un control '
            . 'prenatal). Escribe sus datos, agrega los medicamentos y corrígelo cuando quieras: no se registra '
            . 'como paciente ni aparece en la historia.';
    }

    public function ayudaDeLaPareja(): string
    {
        return 'Son texto libre: la pareja no se busca ni se registra como paciente de tu consulta.';
    }

    /** El estado de la sección como pastilla: si la consulta ya tiene récipe de la pareja. */
    public function estado(): array
    {
        if ($this->pareja === null) {
            return [
                'tono' => 'neutro',
                'icono' => 'bi-dash-circle',
                'texto' => 'Sin récipe de la pareja',
                'titulo' => 'Esta consulta todavía no tiene récipe de la pareja',
            ];
        }

        $total = $this->pareja->items->count();

        return [
            'tono' => 'marca',
            'icono' => 'bi-prescription2',
            'texto' => $total . ($total === 1 ? ' medicamento' : ' medicamentos'),
            'titulo' => 'El récipe de la pareja de esta consulta',
        ];
    }

    public function avisoDeEliminar(): string
    {
        $total = $this->pareja === null ? 0 : $this->pareja->items->count();

        return '¿Eliminar el récipe de la pareja de esta consulta? Se borran sus '
            . $total . ($total === 1 ? ' medicamento' : ' medicamentos')
            . ' y los datos de la pareja, y no se puede deshacer. Tu vademécum no se toca.';
    }

    public function urlEliminar(): string
    {
        return $this->urls['eliminar'];
    }

    // ----------------------------------------------------------------------------------------- Editor

    /**
     * El editor: a dónde postea, los datos de la pareja y las filas (de la base, o las del `old()` si el `POST`
     * falló).
     *
     * @return array{titulo:string, urlGuardar:string, cedula:string, nombre:string, filas:array<int,array<string,mixed>>}
     */
    public function editor(): array
    {
        $cedula = $this->pareja->cedula ?? '';
        $nombre = $this->pareja->nombre ?? '';

        if (($this->old['editor'] ?? null) !== null) {
            $cedula = is_string($this->old['cedula'] ?? null) ? $this->old['cedula'] : '';
            $nombre = is_string($this->old['nombre'] ?? null) ? $this->old['nombre'] : '';
        }

        return [
            'titulo' => $this->titulo(),
            'urlGuardar' => $this->urls['guardar'],
            'cedula' => $cedula,
            'nombre' => $nombre,
            'filas' => $this->filasDelEditor($this->pareja?->items),
        ];
    }

    public function largoCedula(): int
    {
        return GuardarRecipeDeLaPareja::LARGO_CEDULA;
    }

    public function largoNombreDeLaPareja(): int
    {
        return GuardarRecipeDeLaPareja::LARGO_NOMBRE;
    }
}
