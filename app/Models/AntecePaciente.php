<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Los antecedentes de un paciente** (`antece_paciente`).
 *
 * La fila es del **paciente**, no de la consulta: se llavea por `numhistoria` + `codeantecedente`, y
 * por eso se ve en la historia y en la próxima consulta (`App\Clinica\Consulta\AntecedentesDelPaciente`
 * tiene el mapa del dato). `antecedentes` es el catálogo del médico y `antece_paciente` la relación con
 * su detalle y su tipo.
 *
 * Las pantallas de la web escriben esta tabla con `DB::table` desde
 * `App\Actions\Pacientes\GuardarAntecedentes` (que es la regla y sigue siendo la única puerta de la
 * web). El modelo existe para el camino genérico del sync del móvil, que necesita un modelo igual que
 * `cola` o `consultas` (`SyncAppDataController::modelFor`): no cambia nada de lo que hace la web.
 */
class AntecePaciente extends Model
{
    protected $table = 'antece_paciente';

    protected $fillable = [
        'reg_medico', 'numhistoria', 'codeantecedente', 'detalles', 'descripcion', 'tipo', 'tipo2',
    ];

    protected $casts = ['numhistoria' => 'integer'];

    /**
     * El rastro del borrado, igual que en `Cola` y `Paciente`: la baja puede venir del teléfono (quitar
     * un antecedente desde la consulta) y sin esto los demás equipos del médico se quedarían con la fila.
     */
    protected static function booted()
    {
        static::deleting(function (AntecePaciente $antecedente) {
            SyncChange::create([
                'reg_medico' => $antecedente->reg_medico,
                'table_name' => 'antece_paciente',
                'record_id' => $antecedente->id,
                'operation' => 'deleted',
                'occurred_at' => now(),
                'source' => 'api',
            ]);
        });
    }
}
