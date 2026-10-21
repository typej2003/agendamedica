<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Models\Consulta;
use App\Models\MotivoConsultaPaciente;
use Illuminate\Support\Collection;

/**
 * **La casilla «Anterior»** (WEB-11.4; casilla por pedido de Alexander, 2026-10-20): trae a la consulta lo que tenía la **consulta
 * anterior de la misma historia**, para no volver a escribir lo que es el mismo problema (el paciente vuelve por lo mismo: primera
 * cita, revisión de exámenes, de estudios, récipe…). Es la casilla `cbx_moti` / `cbx_diag` / `cbx_ante` del escritorio
 * (`w_consulta_principal.srw:6757`, `:11608`, `:13092`), que copia de `consulta - 1` al marcarla.
 *
 * **Qué dice la casilla: «lo de la consulta anterior está en ésta».** Marcada si todo lo de la anterior ya está; marcarla lo trae
 * **sin repetir** lo que ya hay y desmarcarla **saca de ésta lo que coincide con la anterior**. Las dos cosas son idempotentes.
 *
 * Reglas (decididas con Alexander, 2026-10-15):
 *
 *  - **Sólo desde la 2.ª consulta**: la primera de la historia no tiene anterior.
 *  - La «anterior» es la consulta más reciente **de esa historia y ese médico (`reg_medico`), con un número menor, que tenga algo de
 *    ese tipo**: una consulta intermedia sin diagnósticos no corta la cadena.
 *  - Un motivo o diagnóstico que **ya no está en el catálogo** del médico no se trae (no se puede volver a elegir).
 *
 * Por sección, según cómo guarda cada una:
 *
 *  - **Motivos y diagnósticos** guardan al instante: traer y sacar escriben acá, con las Actions de cada sección
 *    (`AgregarMotivoALaConsulta`, `QuitarMotivoDeLaConsulta`, `GuardarDiagnosticosDeLaConsulta`), así que el rastro del sync y las
 *    reglas son las mismas (R3 de PLAN-WEB.md).
 *  - **Récipes, enfermedad actual y tratamiento** guardan con su botón (o el autoguardado del texto): acá sólo se **lee** lo de la
 *    anterior (`recipeAnterior`, `textoAnterior`) y la pantalla lo pone en el editor; se guarda con el guardado de la sección
 *    (`GuardarRecipe`, `GuardarEnfermedadActual`, el texto del examen).
 */
final class CopiarDeLaConsultaAnterior
{
    /** Las columnas de texto libre de `consultas` que se pueden traer: la enfermedad actual y el tratamiento (`laringe`). */
    public const TEXTOS = ['enfermedadactual', 'laringe'];

    public function __construct(
        private MotivosDeLaConsulta $motivos,
        private DiagnosticosDeLaConsulta $diagnosticos,
        private RecipesDeLaConsulta $recipes,
        private AgregarMotivoALaConsulta $agregarMotivo,
        private QuitarMotivoDeLaConsulta $quitarMotivo,
        private GuardarDiagnosticosDeLaConsulta $guardarDiagnosticos,
    ) {
    }

    /** ¿La consulta tiene alguna anterior en su historia? (Es lo que decide si «Anterior» se ofrece.) */
    public function hayAnterior(Consulta $consulta): bool
    {
        return $this->anteriores($consulta)->isNotEmpty();
    }

    // --------------------------------------------------------------------------------------------------- Motivos

    /**
     * Los motivos de la anterior que los tenga (sólo los que siguen en el catálogo) y los códigos que ya están en ésta.
     *
     * @return array{desde:?Consulta, anteriores:array<string,string>, actuales:array<int,string>} `codigo => descripcion` de la
     *         anterior; `desde = null` si ninguna anterior tiene motivos
     */
    public function fuenteDeMotivos(Consulta $consulta): array
    {
        $regMedico = (string) $consulta->reg_medico;
        $actuales = $this->motivos->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->pluck('codigo')->map(fn ($codigo) => (string) $codigo)->all();

        foreach ($this->anteriores($consulta) as $anterior) {
            $filas = $this->motivos->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $anterior->nroconsulta);

            if ($filas->isNotEmpty()) {
                return [
                    'desde' => $anterior,
                    'anteriores' => $filas->filter(fn ($fila) => $fila->enCatalogo)
                        ->mapWithKeys(fn ($fila) => [(string) $fila->codigo => $fila->descripcion])->all(),
                    'actuales' => $actuales,
                ];
            }
        }

        return ['desde' => null, 'anteriores' => [], 'actuales' => $actuales];
    }

    /**
     * Trae los motivos de la anterior que los tenga (lo que ya está no se repite).
     *
     * @return array{copiados:int, desde:?int} cuántos se agregaron y de qué consulta (su número), o `desde = null` si no había nada
     */
    public function motivos(Consulta $consulta, string $origen = 'web'): array
    {
        $fuente = $this->fuenteDeMotivos($consulta);

        if ($fuente['desde'] === null) {
            return ['copiados' => 0, 'desde' => null];
        }

        $copiados = 0;
        foreach (array_keys($fuente['anteriores']) as $codigo) {
            $motivo = $this->motivos->delCatalogo((string) $consulta->reg_medico, (string) $codigo);

            if ($motivo !== null && $this->agregarMotivo->ejecutar($consulta, $motivo, $origen)[1]) {
                $copiados++;
            }
        }

        return ['copiados' => $copiados, 'desde' => (int) $fuente['desde']->nroconsulta];
    }

    /**
     * Saca de ésta los motivos que coinciden con los de la anterior (desmarcar la casilla). No toca el catálogo.
     *
     * @return array{quitados:int, desde:?int}
     */
    public function quitarMotivos(Consulta $consulta, string $origen = 'web'): array
    {
        $fuente = $this->fuenteDeMotivos($consulta);

        if ($fuente['desde'] === null) {
            return ['quitados' => 0, 'desde' => null];
        }

        $ids = MotivoConsultaPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->get(['id', 'codemotivo'])
            ->filter(fn ($fila) => array_key_exists(trim((string) $fila->codemotivo), $fuente['anteriores']))
            ->pluck('id');

        $quitados = 0;
        foreach ($ids as $id) {
            if ($this->quitarMotivo->ejecutar((int) $id, [(string) $consulta->reg_medico], $origen)) {
                $quitados++;
            }
        }

        return ['quitados' => $quitados, 'desde' => (int) $fuente['desde']->nroconsulta];
    }

    // ----------------------------------------------------------------------------------------------- Diagnósticos

    /**
     * Los diagnósticos de la anterior que los tenga (sólo los que siguen en el catálogo) y las filas de ésta.
     *
     * @return array{desde:?Consulta, anteriores:array<string,string>, actuales:array<int,string>} `codigo => nombre` de la anterior
     *         y `id => codigo` de ésta
     */
    public function fuenteDeDiagnosticos(Consulta $consulta): array
    {
        $regMedico = (string) $consulta->reg_medico;
        $actuales = $this->diagnosticos->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->mapWithKeys(fn ($fila) => [(int) $fila->id => trim((string) $fila->codigo)])->all();

        foreach ($this->anteriores($consulta) as $anterior) {
            $filas = $this->diagnosticos->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $anterior->nroconsulta);

            if ($filas->isNotEmpty()) {
                return [
                    'desde' => $anterior,
                    'anteriores' => $filas->filter(fn ($fila) => $fila->enCatalogo)
                        ->mapWithKeys(fn ($fila) => [trim((string) $fila->codigo) => $fila->nombre])->all(),
                    'actuales' => $actuales,
                ];
            }
        }

        return ['desde' => null, 'anteriores' => [], 'actuales' => $actuales];
    }

    /**
     * Trae los diagnósticos de la anterior que los tenga (el detalle de cada uno no se copia: es de aquella atención).
     *
     * @return array{copiados:int, desde:?int}
     */
    public function diagnosticos(Consulta $consulta, string $origen = 'web'): array
    {
        $fuente = $this->fuenteDeDiagnosticos($consulta);

        if ($fuente['desde'] === null) {
            return ['copiados' => 0, 'desde' => null];
        }

        $faltan = array_values(array_diff(array_map('strval', array_keys($fuente['anteriores'])), $fuente['actuales']));
        $copiados = $faltan === [] ? 0 : count($this->guardarDiagnosticos->ejecutar($consulta, [], $faltan, [], null, $origen)->agregados);

        return ['copiados' => $copiados, 'desde' => (int) $fuente['desde']->nroconsulta];
    }

    /**
     * Saca de ésta los diagnósticos que coinciden con los de la anterior (desmarcar la casilla). No toca el catálogo.
     *
     * @return array{quitados:int, desde:?int}
     */
    public function quitarDiagnosticos(Consulta $consulta, string $origen = 'web'): array
    {
        $fuente = $this->fuenteDeDiagnosticos($consulta);

        if ($fuente['desde'] === null) {
            return ['quitados' => 0, 'desde' => null];
        }

        $ids = array_keys(array_filter(
            $fuente['actuales'],
            fn (string $codigo) => array_key_exists($codigo, $fuente['anteriores']),
        ));

        if ($ids !== []) {
            $this->guardarDiagnosticos->ejecutar($consulta, [], [], $ids, null, $origen);
        }

        return ['quitados' => count($ids), 'desde' => (int) $fuente['desde']->nroconsulta];
    }

    // --------------------------------------------------------------------------------------- Récipes y textos

    /**
     * **El último récipe de la anterior que tenga récipes** (el de número más alto con medicamentos del vademécum): lo que la casilla
     * del editor pone en el récipe que se edita. No escribe: se guarda con el botón del récipe (`GuardarRecipe`).
     *
     * @return array{desde:Consulta, numero:int, items:array<int,array{codigo:string, nombre:string, generico:?string, cantidad:int, indicaciones:?string}>}|null
     */
    public function recipeAnterior(Consulta $consulta): ?array
    {
        $regMedico = (string) $consulta->reg_medico;

        foreach ($this->anteriores($consulta) as $anterior) {
            $recipe = $this->recipes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $anterior->nroconsulta)
                ->map(function ($recipe) {
                    $recipe->items = $recipe->items->filter(fn ($item) => $item->enVademecum && $item->codigo !== '')->values();

                    return $recipe;
                })
                ->filter(fn ($recipe) => $recipe->items->isNotEmpty())
                ->last();

            if ($recipe !== null) {
                return [
                    'desde' => $anterior,
                    'numero' => (int) $recipe->numero,
                    'items' => $recipe->items->unique('codigo')->map(fn ($item) => [
                        'codigo' => (string) $item->codigo,
                        'nombre' => (string) $item->nombre,
                        'generico' => $item->generico,
                        'cantidad' => max(1, (int) ($item->cantidad ?? 1)),
                        'indicaciones' => $item->indicaciones,
                    ])->values()->all(),
                ];
            }
        }

        return null;
    }

    /**
     * **El texto de la anterior que lo tenga** en una columna de texto libre (`enfermedadactual` o `laringe`, el tratamiento). No
     * escribe: la pantalla lo pone en el campo y se guarda con el guardado de la sección.
     *
     * @return array{desde:Consulta, texto:string}|null
     */
    public function textoAnterior(Consulta $consulta, string $columna): ?array
    {
        if (! in_array($columna, self::TEXTOS, true)) {
            throw new \InvalidArgumentException('Columna de texto desconocida: ' . $columna);
        }

        foreach ($this->anteriores($consulta, [$columna]) as $anterior) {
            $texto = trim((string) $anterior->{$columna});

            if ($texto !== '') {
                return ['desde' => $anterior, 'texto' => $texto];
            }
        }

        return null;
    }

    /**
     * Las consultas de la misma historia y médico con número menor, de la más reciente a la más vieja.
     *
     * @param  array<int,string>  $columnas  columnas extra que hacen falta (los textos)
     * @return Collection<int,Consulta>
     */
    private function anteriores(Consulta $consulta, array $columnas = []): Collection
    {
        return Consulta::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', $consulta->numhistoria)
            ->where('nroconsulta', '<', $consulta->nroconsulta)
            ->orderByDesc('nroconsulta')
            ->get(array_merge(['id', 'nroconsulta', 'fecha'], $columnas));
    }
}
