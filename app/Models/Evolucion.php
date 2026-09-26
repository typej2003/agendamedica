<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evolucion extends Model
{
    use HasFactory;

    protected $table = 'evolucion';

    protected $fillable = [
        'reg_medico', 'especialidad', 'ciudad', 'logo', 'linea_1', 'linea_2', 'linea_3',
        'lineag_1', 'lineag_2', 'clave', 'fecha', 'rif', 'reporte_vacio', 'moneda',
        'impuesto', 'por_impues', 'am_pm', 'cantidad_paciente', 'lunes_i', 'lunes_f',
        'martes_i', 'martes_f', 'miercoles_i', 'miercoles_f', 'jueves_i', 'jueves_f',
        'vienes_i', 'viernes_f', 'sabado_i', 'sabado_f', 'tiempo_paci', 'domingo_i',
        'domigo_f', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado',
        'domingo', 'feriado', 'cedula', 'min_salud', 'col_med', 'cita_previa',
        'telefono', 'cobra_honorarios', 'por_cobranza', 'por_retencin_seg',
        'por_retencin_part', 'accionista', 'consultorio', 'contrasena', 'paga_iva',
        'sms_user', 'sms_clave', 'sms_cantidad_total', 'sms_telefono_llamada',
        'sms_sexo_medico', 'sms_proveedor', 'correo_med', 'pais', 'prefi_1',
        'prefi_2', 'prefi_3', 'nom_moneda', 'nom_impuesto', 'impuesto_vale', 'slug',
        'plantilla_cita', 'plantilla_cumple',
    ];

    /**
     * Relación con el modelo Medico a través de reg_medico.
     */
    public function medico()
    {
        return $this->belongsTo(Medico::class, 'reg_medico', 'reg_medico');
    }
}