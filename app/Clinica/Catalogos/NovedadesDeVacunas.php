<?php

namespace App\Clinica\Catalogos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Las vacunas del catalogo inicial que el medico todavia no tiene** (WEB-12.8): las filas de `vacunas` sin dueño (`reg_medico` NULL) cuyo
 * `codi_vacuna` no esta entre las del consultorio. Es lo que ofrece el boton «Cargar el catalogo inicial» (WEB-7.4.4e) y lo que copia
 * `CopiarVacunasIniciales`. Una vacuna que el medico ya tiene —aunque la haya editado— no es una novedad.
 */
class NovedadesDeVacunas
{
    /** @return Collection<int,object>  `id`, `codi_vacuna`, `vacuna` y `dosis_total` (cuantas dosis trae), por codigo */
    public function de(string $regMedico): Collection
    {
        $propias = DB::table('vacunas')->where('reg_medico', $regMedico)->pluck('codi_vacuna')->map(fn ($c) => (string) $c)->all();
        $dosis = DB::table('vacunas_dosis')->whereNull('reg_medico')->selectRaw('codi_vacuna, count(*) as total')->groupBy('codi_vacuna')->pluck('total', 'codi_vacuna');

        return DB::table('vacunas')->whereNull('reg_medico')->orderBy('id')->get()
            ->unique('codi_vacuna')
            ->reject(fn ($v) => in_array((string) $v->codi_vacuna, $propias, true))
            ->map(function ($v) use ($dosis) {
                $v->dosis_total = (int) ($dosis[$v->codi_vacuna] ?? 0);

                return $v;
            })
            ->values();
    }
}
