<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\NeumologiaDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar la neumología pediátrica de una consulta** (2026-10-19): las tres solapas en sus tres columnas de
 * `consultas` —`rayosx`, `faringe` (espirometría) y `broncoscopia`—, que es lo que hace el escritorio en su
 * `UPDATE` (`w_consulta_principal.srw:2861-2862` y `:2826`).
 *
 * Escribe **sólo la columna que cambió**, con su rastro por columna en `sync_changes` (`source = web`) y el
 * last-write-wins del sync. No crea filas: la consulta ya existe.
 */
final class GuardarNeumologiaDeLaConsulta
{
    /**
     * @param  array<string,?string>  $valores  los tres textos, por clave de solapa
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro
     * @return int  cuántas columnas quedaron con un texto nuevo
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): int {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $guardadas = 0;

        foreach (NeumologiaDeLaConsulta::campos() as $clave => $campo) {
            $columna = $campo['columna'];
            $texto = TextoDeLaConsulta::limpio($valores[$clave] ?? null);

            if ($texto === TextoDeLaConsulta::limpio($consulta->{$columna})) {
                continue;
            }

            if (! RegistroDeCambios::permite('consultas', (int) $consulta->id, $columna, $cuando)) {
                continue;
            }

            $consulta->{$columna} = $texto;
            $consulta->save();

            RegistroDeCambios::registrar(
                'consultas',
                (int) $consulta->id,
                $consulta->reg_medico,
                $columna,
                $texto,
                $cuando,
                $origen,
            );

            $guardadas++;
        }

        return $guardadas;
    }
}
