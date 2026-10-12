<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Consulta\RecipesDeLaConsulta;
use Illuminate\Support\Facades\DB;

/**
 * **Dónde se usa un medicamento del vademécum** (WEB-8.1a): la regla que decide si se puede quitar del catálogo.
 *
 * Un medicamento está **en uso** cuando alguna de estas tres cosas lo nombra por su `codemedicina`, dentro del
 * mismo `reg_medico`:
 *
 *  - un récipe del paciente (`recipes`): se cuentan **récipes** —`nrohistoria` + `nroconsulta` + `recipe`, con el
 *    `NULL` del escritorio como el récipe 1, la misma agrupación de `RecipesDeLaConsulta::numeroDeGrupo`—, no filas;
 *  - el récipe de la pareja (`recipes_pareja`): uno por consulta, así que se cuentan consultas;
 *  - un tratamiento del médico (`recipe_grupo_detalle`): se dicen **cuáles**, por su nombre.
 *
 * Es lo que el escritorio **no** comprueba: `w_agregar_vademecum` borra con un `DELETE` directo y la base del
 * PowerBuilder tiene `recipes.codemedicina` con `ON DELETE CASCADE` hacia `vademecum`, o sea que borrar un
 * medicamento arrastraba los récipes de los pacientes que lo tenían. La web no lo permite: avisa y deja que el
 * médico decida (decisión del usuario, 2026-10-11).
 *
 * Sólo lectura: quitar es `App\Actions\Catalogos\QuitarMedicamentoDelCatalogo`, que vuelve a preguntar dentro de
 * su transacción.
 */
final class UsoDeUnMedicamento
{
    /** @param  array<int,string>  $tratamientos  los nombres de los tratamientos que lo llevan */
    private function __construct(
        private int $recipes,
        private int $parejas,
        private array $tratamientos,
    ) {
    }

    /** Dónde se usa el medicamento de código `$codigo` del médico `$regMedico`. */
    public static function de(string $regMedico, string $codigo): self
    {
        $codigo = trim($codigo);

        $recipes = DB::table('recipes')
            ->where('reg_medico', $regMedico)
            ->where('codemedicina', $codigo)
            ->get(['nrohistoria', 'nroconsulta', 'recipe'])
            ->map(fn ($fila) => (int) $fila->nrohistoria . '|' . (int) $fila->nroconsulta . '|' . RecipesDeLaConsulta::numeroDeGrupo($fila->recipe))
            ->unique()
            ->count();

        $parejas = DB::table('recipes_pareja')
            ->where('reg_medico', $regMedico)
            ->where('codemedicina', $codigo)
            ->get(['nrohistoria', 'nroconsulta'])
            ->map(fn ($fila) => (int) $fila->nrohistoria . '|' . (int) $fila->nroconsulta)
            ->unique()
            ->count();

        $codigosDeTratamientos = DB::table('recipe_grupo_detalle')
            ->where('reg_medico', $regMedico)
            ->where('codemedicina', $codigo)
            ->pluck('codigo')
            ->map(fn ($valor) => trim((string) $valor))
            ->unique()
            ->values();

        $tratamientos = $codigosDeTratamientos->isEmpty()
            ? []
            : DB::table('recipe_grupo')
                ->where('reg_medico', $regMedico)
                ->whereIn('codigo', $codigosDeTratamientos->all())
                ->orderBy('tratamiento')
                ->pluck('tratamiento')
                ->map(fn ($nombre) => trim((string) $nombre))
                ->filter(fn ($nombre) => $nombre !== '')
                ->unique()
                ->values()
                ->all();

        // Un código de tratamiento sin encabezado (el legado no lo impide) sigue siendo un uso: se dice igual.
        if ($tratamientos === [] && $codigosDeTratamientos->isNotEmpty()) {
            $tratamientos = $codigosDeTratamientos->map(fn ($codigoDelTratamiento) => 'tratamiento ' . $codigoDelTratamiento)->all();
        }

        return new self($recipes, $parejas, $tratamientos);
    }

    public function enUso(): bool
    {
        return $this->recipes > 0 || $this->parejas > 0 || $this->tratamientos !== [];
    }

    public function recipes(): int
    {
        return $this->recipes;
    }

    public function parejas(): int
    {
        return $this->parejas;
    }

    /** @return array<int,string> */
    public function tratamientos(): array
    {
        return $this->tratamientos;
    }

    /**
     * Dónde está, en una frase: «3 récipes, 1 récipe de la pareja y los tratamientos «A» y «B»». Es lo que el
     * médico lee para decidir qué hacer; no dice «en uso» a secas.
     */
    public function descripcion(): string
    {
        $partes = [];

        if ($this->recipes > 0) {
            $partes[] = $this->recipes . ($this->recipes === 1 ? ' récipe de un paciente' : ' récipes de pacientes');
        }

        if ($this->parejas > 0) {
            $partes[] = $this->parejas . ($this->parejas === 1 ? ' récipe de la pareja' : ' récipes de la pareja');
        }

        if ($this->tratamientos !== []) {
            $nombres = array_map(fn ($nombre) => '«' . $nombre . '»', $this->tratamientos);
            $ultimo = array_pop($nombres);
            $lista = $nombres === [] ? $ultimo : implode(', ', $nombres) . ' y ' . $ultimo;
            $partes[] = (count($this->tratamientos) === 1 ? 'el tratamiento ' : 'los tratamientos ') . $lista;
        }

        if (count($partes) <= 1) {
            return $partes[0] ?? '';
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }
}
