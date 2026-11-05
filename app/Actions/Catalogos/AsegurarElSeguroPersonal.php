<?php

namespace App\Actions\Catalogos;

use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Models\Medico;
use Illuminate\Support\Facades\DB;

/**
 * **El consultorio nunca se queda sin seguros** (O-60, Alexander, 2026-11-05): si no tiene ninguna fila en
 * `seg_emp`, se le crea la de **PERSONAL** (`00`, la misma que usa el escritorio para el paciente que se
 * atiende por su cuenta). Así el alta de un paciente no se encuentra «— Sin seguro —» y el aviso de «este
 * consultorio todavía no tiene seguros cargados».
 *
 *  - **La regla es «no tiene ninguno», no «no tiene PERSONAL»**: un consultorio con seguros propios —aunque
 *    ninguno se llame así— no se toca. Es idempotente: dos llamadas dejan una sola fila.
 *  - **Sólo agrega filas**: la tabla `seg_emp` es del legado, por `reg_medico`; no se renombra ni se
 *    reestructura nada y no se escribe el rastro de `sync_changes` (es un catálogo, no un dato clínico).
 *  - La usa la **migración** que rellena los consultorios que ya existen
 *    (`2026_11_05_140000_sembrar_el_seguro_personal_en_los_consultorios`), escrita aparte y autocontenida, y
 *    el **alta del médico** (`CargarCatalogosInicialesAlMedico` y `AprobarSolicitudDeMedico`).
 *  - **Ojo con el escritorio**: la carga inicial de un consultorio que trabaja con PowerBuilder trae sus
 *    propios `seg_emp`; quien llama a esta Action en el alta es el que tiene que saltarlo (la misma guarda de
 *    `CargarCatalogosInicialesAlMedico`: `sync_cargas` o `sync_credentials`). Acá no se mira: esta Action sólo
 *    responde a «¿tiene filas en `seg_emp`?».
 */
final class AsegurarElSeguroPersonal
{
    /** El nombre que le pone el escritorio al seguro por defecto, en mayúsculas. */
    public const NOMBRE = 'PERSONAL';

    /** `seg_emp.reg_medico` es `varchar(20)`: un registro más largo no se puede guardar. */
    private const LARGO_REG_MEDICO = 20;

    /**
     * Deja el seguro «Personal» en un consultorio si **no tiene ninguno**.
     *
     * @return bool `true` si lo creó; `false` si ya había seguros (o el registro no se puede guardar)
     */
    public function paraRegistro(string $regMedico): bool
    {
        $regMedico = trim($regMedico);

        if ($regMedico === '' || mb_strlen($regMedico) > self::LARGO_REG_MEDICO) {
            return false;
        }

        // La regla es «no tiene ninguno»: con seguros propios no se agrega nada.
        if (DB::table('seg_emp')->where('reg_medico', $regMedico)->exists()) {
            return false;
        }

        DB::table('seg_emp')->insert([
            'reg_medico' => $regMedico,
            'codesegemp' => CatalogoDeSeguros::PERSONAL,
            'nombre' => self::NOMBRE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Lo mismo en **cada consultorio del médico**: el suyo y los que le dieron (`registrosDeTrabajo()`, que ya
     * viene sin vacíos ni repetidos). Un médico que trabaja en dos consultorios deja PERSONAL en los dos que
     * estén vacíos.
     *
     * @return int cuántos consultorios estrenaron el seguro
     */
    public function paraElMedico(Medico $medico): int
    {
        $creados = 0;

        foreach ($medico->registrosDeTrabajo() as $registro) {
            if ($this->paraRegistro($registro)) {
                $creados++;
            }
        }

        return $creados;
    }
}
