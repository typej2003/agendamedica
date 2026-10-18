<?php

namespace App\Actions\Catalogos;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Las líneas (medicamentos) de un tratamiento del catálogo inicial** (WEB-12.8): `recipe_grupo_detalle` sin dueño, enlazadas al tratamiento inicial por su código.
 * Cada línea es un medicamento **del vademécum inicial** (no se repite en el tratamiento), con su cantidad (entera, de 1 a 999) y sus indicaciones. El orden es el de
 * alta. Sólo toca filas sin dueño. Al copiar el tratamiento a un médico, `CopiarCatalogoInicial` lleva estas líneas y mapea cada medicamento a su vademécum.
 */
final class GuardarLineaDeTratamientoInicial
{
    public const LARGO_INDICACIONES = 2000;

    /**
     * @param  string  $codigoTratamiento  el `codigo` del tratamiento inicial
     * @param  int|null  $id  la línea que se edita (`null` = alta)
     *
     * @throws InvalidArgumentException con el mensaje que ve el administrador
     */
    public function ejecutar(string $codigoTratamiento, array $datos, ?int $id = null): int
    {
        if (! DB::table('recipe_grupo')->whereNull('reg_medico')->where('codigo', $codigoTratamiento)->exists()) {
            throw new InvalidArgumentException('Ese tratamiento no está en el catálogo inicial.');
        }

        $medicina = trim((string) ($datos['codemedicina'] ?? ''));
        if ($medicina === '' || ! DB::table('vademecum')->whereNull('reg_medico')->where('codemedicina', $medicina)->exists()) {
            throw new InvalidArgumentException('Elige un medicamento del vademécum inicial.');
        }

        $cantidad = trim((string) ($datos['cantidad'] ?? ''));
        if ($cantidad === '') {
            $cantidad = '1';
        }
        if (! preg_match('/^\d{1,3}$/', $cantidad) || (int) $cantidad < 1) {
            throw new InvalidArgumentException('La cantidad tiene que ser un número entero de 1 a 999.');
        }

        $indicaciones = trim((string) ($datos['indicaciones'] ?? ''));
        if (mb_strlen($indicaciones) > self::LARGO_INDICACIONES) {
            throw new InvalidArgumentException('Las indicaciones no pueden pasar de ' . self::LARGO_INDICACIONES . ' caracteres.');
        }

        return DB::transaction(function () use ($codigoTratamiento, $medicina, $cantidad, $indicaciones, $id) {
            $lineas = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('codigo', $codigoTratamiento)->lockForUpdate()->get();

            if ($id !== null && ! $lineas->contains('id', $id)) {
                throw new InvalidArgumentException('Esa línea no está en el tratamiento.');
            }
            if ($lineas->first(fn ($l) => (int) $l->id !== (int) $id && trim((string) $l->codemedicina) === $medicina)) {
                throw new InvalidArgumentException('Ese medicamento ya está en el tratamiento.');
            }

            $fila = ['codemedicina' => $medicina, 'cantidad' => (int) $cantidad, 'indicaciones' => $indicaciones === '' ? null : $indicaciones];

            if ($id === null) {
                return (int) DB::table('recipe_grupo_detalle')->insertGetId($fila + [
                    'reg_medico' => null, 'codigo' => $codigoTratamiento, 'descripcion' => null,
                    'orden' => (int) $lineas->max('orden') + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('id', $id)->update($fila + ['updated_at' => now()]);

            return $id;
        });
    }

    public function quitar(int $id): void
    {
        if (DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('id', $id)->delete() === 0) {
            throw new InvalidArgumentException('Esa línea no está en el catálogo inicial.');
        }
    }
}
