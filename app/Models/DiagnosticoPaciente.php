<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Diagnósticos de una consulta — tabla legada. La descripción sale de `diagnosticos` por `codediagnostico`.
 *
 * La baja puede venir del **teléfono** desde A1 de PLAN-MOVIL.md (quitar un diagnóstico desde la
 * consulta), y por eso el rastro del borrado se deja acá, igual que en `Cola` y `Paciente`: es lo que le
 * permite al delta (`eliminados`) avisarle a los demás equipos del médico que esa fila ya no está.
 */
class DiagnosticoPaciente extends Model
{
    protected $table = 'diagnostico_paciente';

    protected $fillable = ['reg_medico', 'nrohistoria', 'nroconsulta', 'codediagnostico', 'detalle_diagnostco', 'orden'];

    protected $casts = ['nrohistoria' => 'integer', 'nroconsulta' => 'integer', 'orden' => 'integer'];

    protected static function booted()
    {
        static::deleting(function (DiagnosticoPaciente $diagnostico) {
            SyncChange::create([
                'reg_medico' => $diagnostico->reg_medico,
                'table_name' => 'diagnostico_paciente',
                'record_id' => $diagnostico->id,
                'operation' => 'deleted',
                'occurred_at' => now(),
                'source' => 'api',
            ]);
        });
    }
}
