<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Solicitud de registro de un médico** (ROADMAP §10.4, fase 2): lo que manda desde `/registro/medico`, ya con
 * el correo verificado. No es una cuenta: el administrador la aprueba (nace el `User` y la ficha de `medicos`) o
 * la rechaza con un motivo. Ver `App\RegistroDeMedicos` y `App\Actions\Medicos\AprobarSolicitudDeMedico`.
 */
class SolicitudMedico extends Model
{
    public const PENDIENTE = 'pendiente';
    public const APROBADA = 'aprobada';
    public const RECHAZADA = 'rechazada';

    public const ESTADOS = [self::PENDIENTE, self::APROBADA, self::RECHAZADA];

    protected $table = 'solicitudes_medico';

    protected $fillable = [
        'prefijo', 'nombres', 'apellidos', 'cedula', 'mpps', 'colegio', 'specialty_id', 'telefono', 'email',
        'ciudad', 'mensaje', 'carnet_ruta', 'carnet_tipo', 'carnet_nombre', 'estado', 'motivo_rechazo',
        'revisada_por', 'revisada_el', 'medico_id', 'reg_medico',
    ];

    protected $casts = [
        'revisada_el' => 'datetime',
    ];

    public function especialidad()
    {
        return $this->belongsTo(Specialty::class, 'specialty_id');
    }

    public function revisor()
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    public function medico()
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    public function nombreCompleto(): string
    {
        return trim(($this->prefijo ? $this->prefijo . ' ' : '') . $this->nombres . ' ' . $this->apellidos);
    }

    /**
     * El `reg_medico` que la bandeja propone al aprobar: `MPPS-<número>`, la misma forma que ya usan los médicos
     * dados de alta a mano. El administrador lo confirma o lo cambia (por ejemplo, por el de un consultorio del
     * escritorio que ya existe).
     */
    public function regMedicoPropuesto(): string
    {
        $numero = preg_replace('/[^0-9A-Za-z]/', '', (string) $this->mpps);

        return $numero === '' ? '' : 'MPPS-' . $numero;
    }
}
