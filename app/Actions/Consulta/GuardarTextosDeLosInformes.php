<?php

namespace App\Actions\Consulta;

use App\Models\TextoCardiacoMedico;
use App\Sync\RegistroDeCambios;
use Illuminate\Support\Carbon;

/**
 * **Guardar los textos por defecto de los informes del cardiólogo** (C12, 2026-11-07): una fila por médico, con rastro
 * `created` en `sync_changes` la primera vez y `updated` por columna cuando cambia un texto.
 */
final class GuardarTextosDeLosInformes
{
    /**
     * @param  array{duplex_superior?:?string, protocolo_marcapaso?:?string, texto_marca?:?string}  $textos
     */
    public function ejecutar(string $regMedico, array $textos, ?string $origen = 'web'): TextoCardiacoMedico
    {
        $fila = TextoCardiacoMedico::firstOrNew(['reg_medico' => $regMedico]);
        $nueva = ! $fila->exists;

        $columnas = ['duplex_superior', 'protocolo_marcapaso', 'texto_marca'];
        $cambiadas = [];

        foreach ($columnas as $columna) {
            if (! array_key_exists($columna, $textos)) {
                continue;
            }

            $valor = $textos[$columna] === null || trim((string) $textos[$columna]) === '' ? null : trim((string) $textos[$columna]);

            if ($fila->{$columna} !== $valor) {
                $fila->{$columna} = $valor;
                $cambiadas[] = $columna;
            }
        }

        if ($nueva || $cambiadas !== []) {
            $fila->save();
        }

        if ($origen !== null) {
            $id = (int) $fila->getKey();

            if ($nueva) {
                RegistroDeCambios::registrarOperacion('texto_cardiaco_medico', $id, $regMedico, 'created', null, null, Carbon::now(), $origen);
            } else {
                foreach ($cambiadas as $columna) {
                    RegistroDeCambios::registrarOperacion('texto_cardiaco_medico', $id, $regMedico, 'updated', $columna, $fila->{$columna}, Carbon::now(), $origen);
                }
            }
        }

        return $fila;
    }
}
