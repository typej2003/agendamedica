<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **Ningún consultorio se queda sin seguros** (O-60, Alexander, 2026-11-05): el alta de un paciente de un
 * consultorio que no tiene seguros cargados deja el campo «Seguro / empresa» en «— Sin seguro —» y avisa que
 * el consultorio todavía no tiene seguros. Esta migración rellena lo que ya existe: cada consultorio —cada
 * `reg_medico` de `medicos` y de `medico_registros`— que **no tenga ninguna** fila en `seg_emp` estrena la
 * fila `00` / `PERSONAL`, la misma que usa el escritorio para el paciente que se atiende por su cuenta
 * (`CatalogoDeSeguros::PERSONAL`, por eso el nombre va en mayúsculas).
 *
 *  - **Idempotente**: si el consultorio ya tiene cualquier fila —aunque no se llame «Personal» y aunque su
 *    código no sea `00`— no se toca. La regla mira la tabla vacía, no el código: un consultorio con sus
 *    propios seguros no necesita el nuestro.
 *  - **Sólo agrega filas**: no borra, no renombra y no reestructura la tabla del legado. No toca lo que ya
 *    está, y `down()` no borra nada porque no se puede distinguir la fila que creó esta migración de una que
 *    cargó el consultorio (y borrar datos del médico no es reversible).
 *  - **Es autocontenida** (SQL/DML acá adentro, sin llamar a código de la aplicación): la Action
 *    `App\Actions\Catalogos\AsegurarElSeguroPersonal` hace lo mismo en el alta, pero una migración no puede
 *    depender de una clase que después cambia.
 *  - **No le hace falta la guarda del escritorio**: la carga inicial de un consultorio que trabaja con
 *    PowerBuilder trae sus propios `seg_emp`, pero si todavía no los trajo, la tabla está vacía y esta
 *    migración sólo deja el «Personal» que el escritorio también tiene.
 *  - Se salta los registros de más de 20 caracteres: `seg_emp.reg_medico` es `varchar(20)` y ahí no entran
 *    (tampoco puede haber filas de ese consultorio, así que no hay nada que respetar).
 */
return new class extends Migration
{
    /** El código de PERSONAL en el legado (el default del alta del escritorio). */
    private const CODIGO = '00';

    /** El nombre que usa el escritorio, en mayúsculas. */
    private const NOMBRE = 'PERSONAL';

    /** `seg_emp.reg_medico` es `varchar(20)`: un registro más largo no se puede guardar. */
    private const LARGO_REG_MEDICO = 20;

    /** Cuántos consultorios se miran por vuelta (para no armar un `whereIn` gigante). */
    private const LOTE = 500;

    public function up(): void
    {
        if (! Schema::hasTable('seg_emp')) {
            return;
        }

        foreach (array_chunk($this->consultorios(), self::LOTE) as $registros) {
            // Una sola consulta por lote: quiénes ya tienen alguna fila (no importa de qué código).
            $conSeguros = DB::table('seg_emp')
                ->whereIn('reg_medico', $registros)
                ->pluck('reg_medico')
                ->map(fn ($registro) => trim((string) $registro))
                ->flip()
                ->all();

            $filas = [];
            foreach ($registros as $registro) {
                if (isset($conSeguros[$registro])) {
                    continue;
                }

                $filas[] = [
                    'reg_medico' => $registro,
                    'codesegemp' => self::CODIGO,
                    'nombre' => self::NOMBRE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($filas !== []) {
                DB::table('seg_emp')->insert($filas);
            }
        }
    }

    public function down(): void
    {
        // No se revierte a propósito: la fila de PERSONAL es un dato del consultorio y borrarla —o intentar
        // adivinar cuál la creó esta migración— dejaría al alta de pacientes sin seguro otra vez.
    }

    /**
     * Los `reg_medico` de los consultorios que existen: el de cada ficha de `medicos` (el doctor y también el
     * centro, que es el tenant donde atienden sus doctores) más los que se dieron por `medico_registros`.
     * Sin vacíos, sin repetidos y sin los que no entran en la columna del legado.
     *
     * @return array<int,string>
     */
    private function consultorios(): array
    {
        $registros = [];

        foreach (['medicos', 'medico_registros'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $registros = array_merge(
                $registros,
                DB::table($tabla)->whereNotNull('reg_medico')->where('reg_medico', '<>', '')->pluck('reg_medico')->all(),
            );
        }

        $limpios = [];
        foreach ($registros as $registro) {
            $registro = trim((string) $registro);

            if ($registro !== '' && mb_strlen($registro) <= self::LARGO_REG_MEDICO) {
                $limpios[$registro] = true;
            }
        }

        return array_keys($limpios);
    }
};
