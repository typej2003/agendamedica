<?php

namespace App\Clinica\Impresion;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Paciente;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Throwable;

/**
 * **Los datos del paciente tal como salen en la cabecera de un documento impreso** (WEB-5.2, reusado por WEB-5.3a):
 * nombre, cédula con su nacionalidad, edad **a la fecha del documento** y dirección.
 *
 * Es una sola regla para todos los documentos (récipes, constancias, reposos, referencias, informes): si cada uno
 * armara su cabecera, el mismo paciente saldría con la edad o la cédula distintas según el papel.
 *
 *  - **Cédula**: `V-12345678`; `null` si el paciente no tiene (el `S.C.` del legado no cuenta como cédula).
 *  - **Edad**: los años cumplidos **a la fecha del documento**, no a la de hoy —un documento reimpreso años después sigue
 *    diciendo la edad que el paciente tenía—; `null` si la fecha de nacimiento no sirve o es posterior al documento.
 *
 * Es una clase **sin estado y sin base de datos** (recibe el modelo ya leído).
 */
final class PacienteImpreso
{
    /**
     * @return array{nombre:string, cedula:?string, edad:?int, direccion:?string}
     */
    public static function datos(Paciente $paciente, string $fecha): array
    {
        return [
            'nombre' => self::nombre($paciente),
            'cedula' => self::cedula($paciente),
            'edad' => self::edadA($paciente->fnacimiento, $fecha),
            'direccion' => CamposDePaciente::texto($paciente->direccion),
        ];
    }

    /** `Nombres Apellidos`, como lo arman los documentos del legado (`nombres + ' ' + apellidos`). */
    public static function nombre(Paciente $paciente): string
    {
        return trim(trim((string) $paciente->nombres) . ' ' . trim((string) $paciente->apellidos));
    }

    /** `V-12345678`: la cédula con su nacionalidad, o `null` si el paciente no tiene. */
    public static function cedula(Paciente $paciente): ?string
    {
        $cedula = CamposDePaciente::cedula($paciente->cedula);

        if ($cedula === null) {
            return null;
        }

        return (CamposDePaciente::texto($paciente->nac) ?? 'V') . '-' . $cedula;
    }

    /** Los años cumplidos a la fecha del documento; `null` si la fecha de nacimiento no sirve o es posterior. */
    public static function edadA($fnacimiento, string $fecha): ?int
    {
        if (FechaClinica::paraInput($fnacimiento) === '') {
            return null;
        }

        try {
            $nacimiento = Carbon::parse(FechaClinica::paraInput($fnacimiento));
            $al = Carbon::parse($fecha);
        } catch (Throwable $e) {
            return null;
        }

        return $nacimiento->greaterThan($al) ? null : $nacimiento->diffInYears($al);
    }
}
