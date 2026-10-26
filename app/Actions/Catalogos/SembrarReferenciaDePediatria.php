<?php

namespace App\Actions\Catalogos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **La referencia de pediatría** (WEB-12.0): las curvas de crecimiento (`percentil_nino`, `percentil_nina`) y el catálogo inicial de vacunas (`tipo_vacuna`,
 * `vacunas` y sus `vacunas_dosis`), sin dueño (`reg_medico` nulo). Los JSON de `database/seeders/data/pediatria/` salen de la **base vacía de pediatría que mandó
 * Alexander** (2026-10-25) con `bridge/tools/exportar-catalogos-asa8.ps1`, sin el stock ni el precio de cada vacuna (son del consultorio) y sin las seis dosis que
 * citan vacunas que esa base no tiene (`08` y `15`).
 *
 *  - **Las curvas** se cargan si la tabla no tiene filas iniciales; si ya las tiene, sólo se completa el **IMC** (`imc_*`) de las filas que lo tienen vacío, por su
 *    rango de edad: la primera siembra salió de una base sin IMC.
 *  - **Las vacunas van juntas** (la dosis y el tipo citan a la vacuna por su código, que no se renumera): se cargan si las tres tablas no tienen filas iniciales.
 *    Si ya hay un catálogo inicial **no se tocan**, salvo con `$reemplazarVacunas`, que lo cambia entero por el de los archivos. Lo que cada médico ya copió no
 *    cambia nunca (`CopiarVacunasIniciales` copia; las novedades se miden por código).
 *  - `percentil_pa_*` y `percentil_pulso_a_m` **no se cargan**: en la base del escritorio traen una fila con columnas que no corresponden a las del esquema.
 *
 *   php artisan pediatria:sembrar-referencia [--reemplazar-vacunas] [--simular]
 */
final class SembrarReferenciaDePediatria
{
    public const CURVAS = ['percentil_nino', 'percentil_nina'];

    /** Las tablas de las vacunas, en el orden en que se cargan (y al revés, en el que se quitan). */
    public const VACUNAS = ['tipo_vacuna', 'vacunas', 'vacunas_dosis'];

    private const IMC = ['imc_b', 'imc_m', 'imc_a'];

    /**
     * @return array<string,array{cargadas:int, quitadas:int, completadas:int, saltada:bool}>
     */
    public function ejecutar(bool $reemplazarVacunas = false, bool $simular = false): array
    {
        $informe = [];

        DB::beginTransaction();

        try {
            foreach (self::CURVAS as $tabla) {
                $informe[$tabla] = $this->curva($tabla);
            }

            $hayVacunas = collect(self::VACUNAS)->contains(fn ($t) => DB::table($t)->whereNull('reg_medico')->exists());
            foreach (self::VACUNAS as $tabla) {
                $informe[$tabla] = ['cargadas' => 0, 'quitadas' => 0, 'completadas' => 0, 'saltada' => $hayVacunas && ! $reemplazarVacunas];
            }

            if (! $hayVacunas || $reemplazarVacunas) {
                foreach (array_reverse(self::VACUNAS) as $tabla) {
                    $informe[$tabla]['quitadas'] = DB::table($tabla)->whereNull('reg_medico')->delete();
                }
                foreach (self::VACUNAS as $tabla) {
                    $informe[$tabla]['cargadas'] = $this->insertar($tabla, $this->leer($tabla));
                }
            }

            $simular ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $informe;
    }

    /** @return array{cargadas:int, quitadas:int, completadas:int, saltada:bool} */
    private function curva(string $tabla): array
    {
        $cuenta = ['cargadas' => 0, 'quitadas' => 0, 'completadas' => 0, 'saltada' => false];
        $filas = $this->leer($tabla);

        if (! DB::table($tabla)->whereNull('reg_medico')->exists()) {
            $cuenta['cargadas'] = $this->insertar($tabla, $filas);

            return $cuenta;
        }

        foreach ($filas as $fila) {
            $imc = array_intersect_key($fila, array_flip(self::IMC));
            if (array_filter($imc, fn ($v) => $v !== null) === []) {
                continue;
            }

            $cuenta['completadas'] += DB::table($tabla)->whereNull('reg_medico')
                ->where('edad_ini_dia', $fila['edad_ini_dia'])->where('edad_fin_dia', $fila['edad_fin_dia'])
                ->whereNull('imc_b')->whereNull('imc_m')->whereNull('imc_a')
                ->update($imc + ['updated_at' => now()]);
        }

        return $cuenta;
    }

    /** @param  array<int,array<string,mixed>>  $filas */
    private function insertar(string $tabla, array $filas): int
    {
        $columnas = array_flip(Schema::getColumnListing($tabla));
        $ahora = now();

        foreach ($filas as $fila) {
            DB::table($tabla)->insert(array_intersect_key($fila, $columnas) + ['reg_medico' => null, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        return count($filas);
    }

    /** @return array<int,array<string,mixed>> */
    private function leer(string $tabla): array
    {
        $archivo = database_path('seeders/data/pediatria/' . $tabla . '.json');

        return json_decode((string) file_get_contents($archivo), true) ?: [];
    }
}
