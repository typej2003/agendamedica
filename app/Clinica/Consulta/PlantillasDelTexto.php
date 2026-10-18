<?php

namespace App\Clinica\Consulta;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Las plantillas de texto de una sección de la consulta** (WEB-6.5): la lista `formato_print` del médico,
 * filtrada por el **tipo** que cada sección usa.
 *
 * El escritorio tiene **una sola** tabla de plantillas para todas las secciones y las distingue por `tipo`
 * (`d_l_enfe_actu_para`, que la ventana recarga con `retrieve('<tipo>')`): `EV` la evolución, `CO` la
 * colposcopía, `CI` la citología, `RE` el resultado de exámenes, `EA` la enfermedad actual… El listado de
 * tipos sale del propio DataWindow del ABM (`d_a_formatos.srd:9`). Sin base de datos propia: lee y arma.
 *
 * Se extrajo de `EvolucionDeLaConsulta::plantillas()` (WEB-4.6) cuando la colposcopía necesitó **la misma**
 * lista con otro tipo: la regla de los "vacíos" del legado (una plantilla sin texto no ofrece nada que
 * copiar) es una sola.
 */
final class PlantillasDelTexto
{
    /**
     * Las plantillas de un tipo, por título.
     *
     * Una plantilla **sin texto** se descarta: al elegirla copiaría un vacío. Una **sin título** se
     * identifica por su código, que es lo único que tiene (la fuente muestra ese campo).
     *
     * @return Collection<int,object{codigo:string, titulo:string, texto:string}>
     */
    public static function de(string $regMedico, string $tipo): Collection
    {
        return DB::table('formato_print')
            ->where('reg_medico', $regMedico)
            ->where('tipo', $tipo)
            ->orderBy('titulo')
            ->orderBy('codigo')
            ->get(['codigo', 'titulo', 'descripcion'])
            ->map(function ($fila) {
                $texto = TextoDeLaConsulta::limpio($fila->descripcion);

                if ($texto === null) {
                    return null;
                }

                return (object) [
                    'codigo' => trim((string) $fila->codigo),
                    'titulo' => TextoDeLaConsulta::limpio($fila->titulo) ?? trim((string) $fila->codigo),
                    'texto' => $texto,
                ];
            })
            ->filter()
            ->values();
    }
}
