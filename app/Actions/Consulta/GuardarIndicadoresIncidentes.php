<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\IndicadoresIncidentes;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar los indicadores incidentes de una consulta** (nefrología pediátrica, 2026-10-19): compone el texto
 * del panel y lo deja en `consultas.resultadoexamencomp`.
 *
 * Es la misma regla que el escritorio (`w_consulta_principal.srw:2821` y `:2850`): al guardar, el resumen
 * compuesto reemplaza la columna. La web guarda **sólo el texto**, igual que la fuente: los números no se
 * guardan en ninguna parte (ver `IndicadoresIncidentes`).
 *
 * Tres cosas que respeta, como las demás escrituras de la consulta:
 *
 *  - **No crea filas vacías**: sin ningún valor no escribe (el panel se puede abrir y salir sin tocar nada).
 *  - **Sólo lo que cambió**: si el texto compuesto es el mismo que ya está, no mueve la fila ni deja rastro.
 *  - **Rastro por columna** en `sync_changes` (`updated`, `source = web`) y el last-write-wins del sync
 *    (`RegistroDeCambios::permite`): `consultas` la sube el escritorio y la escribe el móvil.
 */
final class GuardarIndicadoresIncidentes
{
    /**
     * @param  array<string,mixed>  $valores  los campos del panel (las claves de `IndicadoresIncidentes::claves()`)
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro
     * @return bool  `true` si la columna quedó con el texto nuevo; `false` si no había nada que guardar o una
     *               edición más nueva la pisa
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): bool {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $texto = IndicadoresIncidentes::componer($valores);

        if ($texto === '') {
            return false;
        }

        if (IndicadoresIncidentes::guardado($consulta) === $texto) {
            return true;
        }

        if (! RegistroDeCambios::permite('consultas', (int) $consulta->id, IndicadoresIncidentes::COLUMNA, $cuando)) {
            return false;
        }

        $consulta->{IndicadoresIncidentes::COLUMNA} = $texto;
        $consulta->save();

        RegistroDeCambios::registrar(
            'consultas',
            (int) $consulta->id,
            $consulta->reg_medico,
            IndicadoresIncidentes::COLUMNA,
            $texto,
            $cuando,
            $origen,
        );

        return true;
    }
}
