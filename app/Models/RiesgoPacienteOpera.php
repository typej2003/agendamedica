<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **`riesgo_paciente_opera`**: el **resumen** de la evaluación de riesgo quirúrgico de una consulta: los puntos
 * totales, la clase, la conclusión, las recomendaciones y el nombre de la intervención.
 *
 * Una fila por consulta (clave `historia` + `consulta`), como la deja el escritorio (`w_consulta_principal.srw:22384`).
 */
class RiesgoPacienteOpera extends Model
{
    protected $table = 'riesgo_paciente_opera';

    protected $fillable = [
        'reg_medico', 'historia', 'consulta', 'puntos', 'clase', 'conclusion', 'recomendaciones', 'nombre_intervencion',
    ];

    protected $casts = ['historia' => 'integer', 'consulta' => 'integer', 'puntos' => 'integer'];
}
