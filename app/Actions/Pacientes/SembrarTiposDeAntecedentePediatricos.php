<?php

namespace App\Actions\Pacientes;

use Illuminate\Support\Facades\DB;

/**
 * **Sembrar los siete tipos de antecedente de pediatría** (WEB-12.3) en el catálogo del médico (`tipo_antecedente`, con su `reg_medico`): las solapas de la
 * pestaña *Antecedentes* del escritorio de pediatría (`w_consulta_principal.srw:3069-3082`, `retrieve(historia, '01')` … `'07')`): *Patológicos*, *C.A.*, *Madre*,
 * *Padre*, *Perinatales*, *Alimentación* y *D.P.* (desarrollo psicomotor). Los **códigos** son los mismos `01`–`07` que usa ginecología (para otros
 * nombres: Personal, Familiar…); lo que cambia por especialidad es el **nombre**, y por eso la lectura toma primero los del propio médico.
 *
 * ⚠️ *C.A.* se deja **literal**: no se sabe qué significa (pregunta 16 de `preguntas-para-alexander.md`). Los nombres van en mayúsculas, como los del legado.
 *
 * Es **idempotente y no pisa**: sólo agrega el código que el médico no tiene (aunque lo haya llamado distinto: lo suyo manda). No toca `antecedentes` (los
 * antecedentes concretos de cada médico) ni nada del paciente.
 */
final class SembrarTiposDeAntecedentePediatricos
{
    /** `codetipo => nombre`, en el orden de las solapas del escritorio. */
    public const TIPOS = [
        '01' => 'PATOLÓGICOS',
        '02' => 'C.A.',
        '03' => 'MADRE',
        '04' => 'PADRE',
        '05' => 'PERINATALES',
        '06' => 'ALIMENTACIÓN',
        '07' => 'D.P.',
    ];

    /** @return int cuántos tipos agregó */
    public function ejecutar(string $regMedico): int
    {
        return DB::transaction(function () use ($regMedico) {
            $tiene = DB::table('tipo_antecedente')->where('reg_medico', $regMedico)->pluck('codetipo')->map(fn ($c) => trim((string) $c))->all();
            $agregados = 0;

            foreach (self::TIPOS as $codigo => $nombre) {
                if (in_array($codigo, $tiene, true)) {
                    continue;
                }

                DB::table('tipo_antecedente')->insert([
                    'reg_medico' => $regMedico, 'codetipo' => $codigo, 'descripcion' => $nombre,
                    'tipoantecedente' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $agregados++;
            }

            return $agregados;
        });
    }
}
