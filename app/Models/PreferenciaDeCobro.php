<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **Quién cobra las citas de un médico**: la plataforma (por defecto) o el propio consultorio.
 *
 * El cobro de la cita siempre es del médico (`beneficiario`); lo que cambia es `recaudador` y, con eso, si
 * hay una liquidación pendiente. La plataforma no cobra comisión cuando recauda el médico.
 */
class PreferenciaDeCobro extends Model
{
    public const PLATAFORMA = 'plataforma';
    public const MEDICO = 'medico';

    protected $table = 'preferencias_de_cobro';

    protected $fillable = ['reg_medico', 'recaudador'];

    /** El recaudador elegido por el médico; sin fila, la plataforma. */
    public static function de(?string $regMedico): string
    {
        $regMedico = trim((string) $regMedico);

        if ($regMedico === '') {
            return self::PLATAFORMA;
        }

        $recaudador = static::where('reg_medico', $regMedico)->value('recaudador');

        return $recaudador === self::MEDICO ? self::MEDICO : self::PLATAFORMA;
    }

    /** Guarda la elección: `plataforma` o `medico`. */
    public static function fijar(string $regMedico, string $recaudador): self
    {
        $recaudador = $recaudador === self::MEDICO ? self::MEDICO : self::PLATAFORMA;

        return static::updateOrCreate(['reg_medico' => trim($regMedico)], ['recaudador' => $recaudador]);
    }
}
