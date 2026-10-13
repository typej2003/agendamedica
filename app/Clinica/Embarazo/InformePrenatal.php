<?php

namespace App\Clinica\Embarazo;

use App\Models\PreNatalDesarrolloFino;
use App\Models\PrenaExamenB;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * **Todo lo que lleva el informe del control de embarazo** (WEB-6.6, la pestaña *Reporte*): la gesta, sus
 * controles y su laboratorio, ya leídos y listos para armar la hoja.
 *
 * El escritorio imprime el *Reporte* con `d_informe_pre_nata_peque.srd` —título **«CONTROL DE EMBARAZO»**,
 * A4 horizontal— y cuatro sub-reportes anidados (`:32-35`, todos con `nest_arguments (NroHistoria, gesta)`):
 * la gesta (`d_pre_natal_observa_reporte`), los controles (`d_pre_natal_rutin_abuenas_reporte_peq_re`, sobre
 * `pre_natal_desarrollo_fino`), el laboratorio (`d_resultado_examen_reporte_peque_report`, sobre
 * `prena_exames_b`) y las observaciones (`d_pre_natal_observa_reporte_observa`). Esta clase es esa lectura.
 *
 * ⚠️ **La FPP del informe suma 283, no los 281 de la pestaña.** No es un error de lectura: el sub-reporte de
 * la gesta usa `relativedate(fur, 283)` (`d_pre_natal_observa_reporte.srd:45`) mientras la pestaña viva usa
 * 281 (`d_pre_natal_observa_2.srd:38`). El escritorio muestra un número en pantalla y otro en la hoja
 * impresa, y la web **conserva los dos** (la decisión y el porqué están en `EdadGestacional` y en la wiki):
 * el informe sale con el 283, que es lo que el médico viene leyendo en el papel.
 *
 * Las tres partes salen acotadas al **`reg_medico`** del contexto —como todo en la web clínica— y la gesta
 * es la que se está mirando (la de la URL) o, si no se pidió ninguna, la **actual** (la de mayor número).
 */
final class InformePrenatal
{
    /** Los días que suma el **informe** para la FPP (`relativedate(fur, 283)` en el sub-reporte de la gesta). */
    public const DIAS_DE_LA_FPP_DEL_INFORME = EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO;

    /**
     * Las tres partes del informe, o `null` si esa historia todavía no tiene ninguna gesta (no hay informe
     * que imprimir).
     *
     * @return array{gesta:Gesta, controles:Collection, laboratorios:Collection, hoy:CarbonInterface|null}|null
     */
    public function de(string $regMedico, int $numhistoria, ?int $gestaClave = null, ?CarbonInterface $hoy = null): ?array
    {
        $gestas = new GestasDeLaHistoria();

        $gesta = $gestaClave === null
            ? $gestas->actual($regMedico, $numhistoria, $hoy)
            : collect($gestas->de($regMedico, $numhistoria, $hoy))
                ->first(fn (Gesta $cada) => $cada->clave() === $gestaClave);

        if ($gesta === null) {
            return null;
        }

        return [
            'gesta' => $gesta,
            'controles' => $this->filas(PreNatalDesarrolloFino::class, $regMedico, $numhistoria, $gesta->clave()),
            'laboratorios' => $this->filas(PrenaExamenB::class, $regMedico, $numhistoria, $gesta->clave()),
            'hoy' => $hoy,
        ];
    }

    /**
     * ¿Esta historia tiene alguna gesta? Es lo que decide si el informe se **ofrece** (sin gestas no hay
     * nada que imprimir). Consulta una sola fila, no arma el informe entero.
     */
    public function tieneInforme(string $regMedico, int $numhistoria): bool
    {
        return (new GestasDeLaHistoria())->actual($regMedico, $numhistoria) !== null;
    }

    /**
     * La **FPP del informe** de una FUR: la de esta hoja (283), no la de la pantalla (281).
     */
    public function fpp(?string $fur): ?string
    {
        return EdadGestacional::fpp($fur, self::DIAS_DE_LA_FPP_DEL_INFORME);
    }

    /**
     * La **edad gestacional** de la gesta del informe: la misma fórmula de todas las pantallas (sólo cambia
     * la FPP).
     */
    public function edadGestacional(Gesta $gesta, ?CarbonInterface $hoy = null): ?EdadGestacional
    {
        return EdadGestacional::de($gesta->fur(), $hoy);
    }

    /**
     * Las filas de una de las dos tablas por fecha, de la gesta, ordenadas por fecha.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelo
     */
    private function filas(string $modelo, string $regMedico, int $numhistoria, int $gestaClave): Collection
    {
        return $modelo::query()
            ->where('reg_medico', $regMedico)
            ->where('historia', $numhistoria)
            ->where('gesta_clave', $gestaClave)
            ->orderBy('fecha')
            ->get();
    }
}
