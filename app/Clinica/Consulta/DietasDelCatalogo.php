<?php

namespace App\Clinica\Consulta;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El catálogo de dietas de pediatría** (WEB-12.4): la tabla `dietas` (`cod_dieta`, `dietas` = el título, `descripcion` = el texto y `calorias`), de donde la pestaña
 * *Alimentación* del escritorio de pediatría elige lo que «pasa» a la consulta (`d_dietas_consu`, `w_consulta_principal.srw:24728`). Lo elegido se escribe
 * en la **misma** fila de `dieta_paciente` que la pestaña *Dieta / Recomendaciones* (`histroria` + `consulta`): por eso la web no tiene una pantalla aparte
 * sino que ofrece estas dietas como plantillas de esa pestaña. Los vacíos del legado (`none`) no se ofrecen. Sólo lee.
 */
class DietasDelCatalogo
{
    /**
     * @return Collection<int,object{codigo:string, titulo:string, texto:string}>  por título
     */
    public function de(string $regMedico): Collection
    {
        return DB::table('dietas')->where('reg_medico', $regMedico)->orderBy('dietas')->orderBy('cod_dieta')->get(['cod_dieta', 'dietas', 'descripcion'])
            ->map(function ($fila) {
                $texto = TextoDeLaConsulta::limpio($fila->descripcion);

                if ($texto === null) {
                    return null;
                }

                return (object) [
                    'codigo' => trim((string) $fila->cod_dieta),
                    'titulo' => TextoDeLaConsulta::limpio($fila->dietas) ?? trim((string) $fila->cod_dieta),
                    'texto' => $texto,
                ];
            })
            ->filter()
            ->values();
    }
}
