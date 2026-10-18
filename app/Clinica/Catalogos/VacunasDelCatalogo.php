<?php

namespace App\Clinica\Catalogos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **Lee el catálogo de vacunas del médico** (WEB-7.4.4e): sus filas de `vacunas` y de `vacunas_dosis` (las de su `reg_medico`; nunca las del catálogo
 * inicial, que se copian con `CopiarVacunasIniciales`). El catálogo es corto (veinte o treinta vacunas), así que se lee entero y la búsqueda
 * —sin tildes ni mayúsculas, por nombre, código o cobertura— se hace en memoria.
 */
class VacunasDelCatalogo
{
    /**
     * @return Collection<int,object>  cada vacuna con `dosis` (colección de sus dosis por número) y `aplicadas` (cuántas veces se aplicó)
     */
    public function de(string $regMedico, string $buscar = ''): Collection
    {
        $dosis = DB::table('vacunas_dosis')->where('reg_medico', $regMedico)->orderBy('dosis')->get()->groupBy('codi_vacuna');
        $aplicadas = DB::table('vacuna_paciente')->where('reg_medico', $regMedico)->whereRaw("upper(ejecutada) = 'S'")
            ->selectRaw('codi_vacuna, count(*) as total')->groupBy('codi_vacuna')->pluck('total', 'codi_vacuna');

        $vacunas = DB::table('vacunas')->where('reg_medico', $regMedico)->orderBy('edad_inicial_meses')->orderBy('vacuna')->orderBy('id')->get()
            ->map(function ($v) use ($dosis, $aplicadas) {
                $v->dosis = ($dosis[$v->codi_vacuna] ?? collect())->values();
                $v->aplicadas = (int) ($aplicadas[$v->codi_vacuna] ?? 0);

                return $v;
            });

        $palabras = array_filter(preg_split('/\s+/', self::clave($buscar)));
        if ($palabras === []) {
            return $vacunas->values();
        }

        return $vacunas->filter(function ($v) use ($palabras) {
            $texto = self::clave($v->vacuna . ' ' . $v->codi_vacuna . ' ' . $v->cobertura);

            foreach ($palabras as $palabra) {
                if (! str_contains($texto, $palabra)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    public function encontrar(string $regMedico, int $id): ?object
    {
        return $this->de($regMedico)->firstWhere('id', $id);
    }

    private static function clave(string $texto): string
    {
        return mb_strtolower(Str::ascii(trim($texto)));
    }
}
