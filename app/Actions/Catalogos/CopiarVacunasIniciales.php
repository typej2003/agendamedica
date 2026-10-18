<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\NovedadesDeVacunas;
use Illuminate\Support\Facades\DB;

/**
 * **Copiar el catalogo inicial de vacunas al consultorio** (WEB-12.8). Cada medico tiene su propia configuracion y parte de una copia del
 * catalogo que arma el admin: las filas de `vacunas` y `vacunas_dosis` sin dueño (`reg_medico` NULL).
 *
 *  - Copia **sólo las vacunas que el medico no tiene** (por `codi_vacuna`), con sus dosis. Una vacuna que ya tenia no se toca, no se edita
 *    y no recibe dosis iniciales: lo suyo manda.
 *  - Es **idempotente**: una segunda vez no copia nada. Lo que el admin agregue despues se ofrece como novedad (`NovedadesDeVacunas`).
 *  - Nunca modifica ni borra filas iniciales ni de otro consultorio. No deja rastro en `sync_changes` (el movil no baja estas tablas).
 *
 * @return array{vacunas:int, dosis:int}
 */
final class CopiarVacunasIniciales
{
    public function __construct(private NovedadesDeVacunas $novedades)
    {
    }

    public function ejecutar(string $regMedico): array
    {
        return DB::transaction(function () use ($regMedico) {
            $copiadas = 0;
            $dosis = 0;
            $ahora = now();

            foreach ($this->novedades->de($regMedico) as $vacuna) {
                $fila = collect((array) $vacuna)->except(['id', 'dosis_total', 'created_at', 'updated_at'])->all();
                $fila['reg_medico'] = $regMedico;
                DB::table('vacunas')->insert($fila + ['created_at' => $ahora, 'updated_at' => $ahora]);
                $copiadas++;

                foreach (DB::table('vacunas_dosis')->whereNull('reg_medico')->where('codi_vacuna', $vacuna->codi_vacuna)->orderBy('dosis')->get() as $d) {
                    $nueva = collect((array) $d)->except(['id', 'created_at', 'updated_at'])->all();
                    $nueva['reg_medico'] = $regMedico;
                    DB::table('vacunas_dosis')->insert($nueva + ['created_at' => $ahora, 'updated_at' => $ahora]);
                    $dosis++;
                }
            }

            return ['vacunas' => $copiadas, 'dosis' => $dosis];
        });
    }
}
