<?php

namespace App\Actions\Medicos;

use Illuminate\Support\Facades\DB;

/**
 * **El siguiente `reg_medico` automático** (`med_00001`, `med_00002`…) para el médico o el centro que se da de
 * alta sin escribir uno.
 *
 * Es el máximo número existente con el patrón `med_NNNNN` más uno, mirando todo lugar donde se guarda el
 * `reg_medico` de un médico (`medicos`, `medico_registros` y `users`), así que no repite uno ya usado ni
 * siquiera si el que lo tenía se borró de una de las tablas. Pasado el 99999 simplemente crece.
 *
 * Se llama **dentro de la transacción del alta**: lee con bloqueo (`lockForUpdate`) para que dos altas
 * simultáneas no calculen el mismo número.
 */
class SiguienteRegMedico
{
    public function ejecutar(): string
    {
        return DB::transaction(function () {
            $maximo = 0;

            foreach (['medicos', 'medico_registros', 'users'] as $tabla) {
                $valores = DB::table($tabla)
                    ->where('reg_medico', 'like', 'med_%')
                    ->lockForUpdate()
                    ->pluck('reg_medico');

                foreach ($valores as $valor) {
                    if (preg_match('/^med_(\d+)$/', (string) $valor, $m)) {
                        $maximo = max($maximo, (int) $m[1]);
                    }
                }
            }

            return 'med_' . str_pad((string) ($maximo + 1), 5, '0', STR_PAD_LEFT);
        });
    }
}
