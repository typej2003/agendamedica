<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Models\Consulta;

/**
 * **«Anterior»** (WEB-11.4): copia a la consulta lo que tenía la **consulta anterior de la misma historia** en los **motivos**, los
 * **diagnósticos** y los **récipes**, para no volver a escribir lo que es el mismo problema (consultas relacionadas; si el paciente
 * viene por otra enfermedad, el médico simplemente no lo usa).
 *
 * Reglas (decididas con Alexander, 2026-10-15):
 *
 *  - **Sólo desde la 2.ª consulta**: la primera de la historia no tiene anterior.
 *  - La «anterior» es la consulta más reciente **de esa historia y ese médico, con un número menor, que tenga algo de ese tipo**: una
 *    consulta intermedia sin diagnósticos no corta la cadena.
 *  - **Suma, no reemplaza**: lo que la consulta ya tiene se queda y lo repetido no se duplica (las Actions de cada sección ya lo
 *    garantizan: `AgregarMotivoALaConsulta`, `GuardarDiagnosticosDeLaConsulta`).
 *  - El **récipe se copia completo** (cada medicamento con su cantidad e indicaciones) como un récipe **nuevo** de esta consulta, con la
 *    fecha de la consulta; la propuesta de la roadmap, que la decisión abierta no contradijo.
 *  - Un motivo o diagnóstico que **ya no está en el catálogo** del médico no se copia (no se puede volver a elegir).
 *
 * Reusa las Actions de cada sección, así que el rastro del sync y las reglas son las mismas (R3 de PLAN-WEB.md) y el móvil la puede usar.
 */
final class CopiarDeLaConsultaAnterior
{
    public function __construct(
        private MotivosDeLaConsulta $motivos,
        private DiagnosticosDeLaConsulta $diagnosticos,
        private RecipesDeLaConsulta $recipes,
        private AgregarMotivoALaConsulta $agregarMotivo,
        private GuardarDiagnosticosDeLaConsulta $guardarDiagnosticos,
        private GuardarRecipe $guardarRecipe,
    ) {
    }

    /** ¿La consulta tiene alguna anterior en su historia? (Es lo que decide si «Anterior» se ofrece.) */
    public function hayAnterior(Consulta $consulta): bool
    {
        return $this->anteriores($consulta)->isNotEmpty();
    }

    /**
     * Copia los motivos de la anterior que los tenga.
     *
     * @return array{copiados:int, desde:?int} cuántos se agregaron y de qué consulta (su número), o `desde = null` si no había nada
     */
    public function motivos(Consulta $consulta, string $origen = 'web'): array
    {
        foreach ($this->anteriores($consulta) as $anterior) {
            $filas = $this->motivos->deLaConsulta((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $anterior->nroconsulta);

            if ($filas->isEmpty()) {
                continue;
            }

            $copiados = 0;
            foreach ($filas as $fila) {
                $motivo = $this->motivos->delCatalogo((string) $consulta->reg_medico, $fila->codigo);

                if ($motivo !== null && $this->agregarMotivo->ejecutar($consulta, $motivo, $origen)[1]) {
                    $copiados++;
                }
            }

            return ['copiados' => $copiados, 'desde' => (int) $anterior->nroconsulta];
        }

        return ['copiados' => 0, 'desde' => null];
    }

    /**
     * Copia los diagnósticos de la anterior que los tenga (el detalle de cada uno no se copia: es de aquella atención).
     *
     * @return array{copiados:int, desde:?int}
     */
    public function diagnosticos(Consulta $consulta, string $origen = 'web'): array
    {
        $regMedico = (string) $consulta->reg_medico;

        foreach ($this->anteriores($consulta) as $anterior) {
            $filas = $this->diagnosticos->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $anterior->nroconsulta);

            if ($filas->isEmpty()) {
                continue;
            }

            $codigos = $filas
                ->filter(fn ($fila) => $this->diagnosticos->delCatalogo($regMedico, $fila->codigo) !== null)
                ->pluck('codigo')
                ->all();

            $resultado = $this->guardarDiagnosticos->ejecutar($consulta, [], $codigos, [], null, $origen);

            return ['copiados' => count($resultado->agregados), 'desde' => (int) $anterior->nroconsulta];
        }

        return ['copiados' => 0, 'desde' => null];
    }

    /**
     * Copia los récipes de la anterior que los tenga, **completos**, como récipes nuevos de esta consulta.
     *
     * @return array{copiados:int, desde:?int} cuántos récipes se crearon
     */
    public function recipes(Consulta $consulta, string $origen = 'web'): array
    {
        $regMedico = (string) $consulta->reg_medico;

        foreach ($this->anteriores($consulta) as $anterior) {
            $recipes = $this->recipes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $anterior->nroconsulta)
                ->filter(fn ($recipe) => $recipe->items->isNotEmpty());

            if ($recipes->isEmpty()) {
                continue;
            }

            foreach ($recipes as $recipe) {
                $this->guardarRecipe->ejecutar(
                    $consulta,
                    $recipe->items->map(fn ($item) => [
                        'codemedicina' => $item->codigo,
                        'cantidad' => $item->cantidad,
                        'indicaciones' => $item->indicaciones,
                        'descripcion' => $item->descripcion,
                        'nombre' => $item->nombre,
                    ])->all(),
                    null,
                    null,
                    null,
                    $origen,
                );
            }

            return ['copiados' => $recipes->count(), 'desde' => (int) $anterior->nroconsulta];
        }

        return ['copiados' => 0, 'desde' => null];
    }

    /**
     * Las consultas de la misma historia y médico con número menor, de la más reciente a la más vieja.
     *
     * @return \Illuminate\Support\Collection<int,Consulta>
     */
    private function anteriores(Consulta $consulta): \Illuminate\Support\Collection
    {
        return Consulta::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', $consulta->numhistoria)
            ->where('nroconsulta', '<', $consulta->nroconsulta)
            ->orderByDesc('nroconsulta')
            ->get(['id', 'nroconsulta']);
    }
}
