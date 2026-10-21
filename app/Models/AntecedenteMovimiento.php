<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Un movimiento de los antecedentes de un paciente** (`antecedente_movimientos`, tabla de AppDDR sólo del
 * servidor; decisión del usuario 2026-10-20): un alta, una edición o una baja de una fila de
 * `antece_paciente`, con la consulta, el origen y el usuario.
 *
 * Se escribe **sólo** con `App\Actions\Pacientes\RegistrarMovimientoDeAntecedente` y se lee con
 * `App\Clinica\Consulta\HistorialDeAntecedentes`. No viaja por el sync (ni al escritorio ni al móvil).
 */
class AntecedenteMovimiento extends Model
{
    public const AGREGADO = 'agregado';
    public const EDITADO = 'editado';
    public const QUITADO = 'quitado';

    public const ORIGEN_WEB = 'web';
    public const ORIGEN_MOVIL = 'movil';
    public const ORIGEN_ESCRITORIO = 'escritorio';
    public const ORIGEN_CARGA_INICIAL = 'carga_inicial';

    protected $table = 'antecedente_movimientos';

    protected $fillable = [
        'reg_medico', 'numhistoria', 'codeantecedente', 'descripcion', 'tipo', 'operacion', 'detalles',
        'alerta', 'consulta_id', 'origen', 'user_id', 'ocurrido_en',
    ];

    protected $casts = [
        'numhistoria' => 'integer',
        'alerta' => 'boolean',
        'consulta_id' => 'integer',
        'user_id' => 'integer',
        'ocurrido_en' => 'datetime',
    ];
}
