<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\Iniciales\EspecialidadesDelCatalogoInicial;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Combinar fichas duplicadas del catálogo inicial de medicamentos**: varias filas (`vademecum` con `reg_medico` NULL) pasan a ser **una**, la que sobrevive y
 * conserva su código, con los textos que el administrador eligió o editó y las especialidades que él deje marcadas (la pantalla las propone sumadas).
 *
 *  - Los **tratamientos iniciales** que llevaban una ficha descartada pasan a llevar la que sobrevive; si un tratamiento queda con la misma ficha dos veces, se
 *    conserva la primera línea.
 *  - Las fichas descartadas y sus marcas de especialidad se quitan.
 *  - Todo en una transacción: si algo no valida (el nombre, la repetición con otra ficha), no cambia nada.
 *
 * Como el resto del catálogo inicial, no toca lo que un médico ya copió ni deja rastro en `sync_changes`.
 */
final class CombinarMedicamentosIniciales
{
    /**
     * @param  array<int,int|string>  $ids  las fichas a combinar (2 o más)
     * @param  int  $sobrevive  la que queda; tiene que estar en `$ids`
     * @param  array<string,mixed>  $datos  `columna => valor` de la ficha resultante (los `campos` de «medicamentos»)
     * @param  array<int,int|string>  $especialidades  las especialidades de la ficha resultante (`[]` = general)
     * @return int  cuántas fichas se descartaron
     *
     * @throws InvalidArgumentException con el mensaje que ve el administrador
     */
    public function ejecutar(array $ids, int $sobrevive, array $datos, array $especialidades): int
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->count() < 2) {
            throw new InvalidArgumentException('Marca al menos dos medicamentos para combinar.');
        }
        if (! $ids->contains($sobrevive)) {
            throw new InvalidArgumentException('Elige cuál ficha se conserva.');
        }

        return DB::transaction(function () use ($ids, $sobrevive, $datos, $especialidades) {
            $fichas = DB::table('vademecum')->whereNull('reg_medico')->whereIn('id', $ids->all())->lockForUpdate()->get();

            if ($fichas->count() !== $ids->count()) {
                throw new InvalidArgumentException('Alguno de esos medicamentos ya no está en el catálogo inicial.');
            }

            $codigoFinal = trim((string) $fichas->firstWhere('id', $sobrevive)->codemedicina);
            $descartadas = $fichas->where('id', '!=', $sobrevive);
            $codigosDescartados = $descartadas->map(fn ($f) => trim((string) $f->codemedicina))->all();

            // Los tratamientos iniciales que llevaban una ficha descartada.
            $lineas = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->get()
                ->filter(fn ($l) => in_array(trim((string) $l->codemedicina), $codigosDescartados, true));
            if ($lineas->isNotEmpty()) {
                DB::table('recipe_grupo_detalle')->whereIn('id', $lineas->pluck('id')->all())->update(['codemedicina' => $codigoFinal]);
            }
            foreach ($lineas->pluck('codigo')->unique() as $tratamiento) {
                $repetidas = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('codigo', $tratamiento)
                    ->orderBy('orden')->orderBy('id')->get()
                    ->filter(fn ($l) => trim((string) $l->codemedicina) === $codigoFinal)->slice(1);
                if ($repetidas->isNotEmpty()) {
                    DB::table('recipe_grupo_detalle')->whereIn('id', $repetidas->pluck('id')->all())->delete();
                }
            }

            foreach ($descartadas as $ficha) {
                DB::table('vademecum')->whereNull('reg_medico')->where('id', $ficha->id)->delete();
                EspecialidadesDelCatalogoInicial::quitarFila('medicamentos', (int) $ficha->id);
            }

            // Ya sin las duplicadas, la validación de «no repetir» sólo compara con las demás fichas.
            app(GuardarFilaDeCatalogoInicial::class)->ejecutar('medicamentos', $datos, $sobrevive, $especialidades);

            return $descartadas->count();
        });
    }
}
