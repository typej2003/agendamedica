<?php

namespace App\RegistroDeMedicos;

use App\Models\SolicitudMedico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Segundo paso del **registro de médicos**: comprobar el código del correo y **guardar la solicitud**, que
 * queda pendiente hasta que un administrador la revise. No crea cuenta: el médico no puede entrar todavía.
 *
 * Revalida que no haya otra en revisión con el mismo correo o cédula (dos pestañas pueden llegar juntas) y,
 * guardada, avisa al médico y a los administradores.
 */
final class ConfirmarSolicitudDeMedico
{
    private const INTENTOS_MAXIMOS = 5;

    private const CAMPOS = [
        'prefijo', 'nombres', 'apellidos', 'cedula', 'mpps', 'colegio', 'specialty_id', 'telefono', 'email',
        'ciudad', 'mensaje', 'carnet_ruta', 'carnet_tipo', 'carnet_nombre',
    ];

    public function __construct(
        private SolicitudEnCurso $enCurso,
        private CorreosDeLaSolicitud $correos,
    ) {
    }

    public function ejecutar(string $codigo): SolicitudMedico
    {
        $datos = $this->enCurso->datos();

        if ($datos === null) {
            throw ValidationException::withMessages([
                'codigo' => 'La solicitud venció o la sesión cambió. Vuelve a empezar.',
            ]);
        }

        if (now()->timestamp > (int) ($datos['expira'] ?? 0)) {
            throw ValidationException::withMessages([
                'codigo' => 'El código venció. Pide uno nuevo.',
            ]);
        }

        if ((int) ($datos['intentos'] ?? 0) >= self::INTENTOS_MAXIMOS) {
            throw ValidationException::withMessages([
                'codigo' => 'Demasiados intentos con ese código. Pide uno nuevo.',
            ]);
        }

        if (! Hash::check($codigo, (string) ($datos['codigo_hash'] ?? ''))) {
            $datos['intentos'] = (int) ($datos['intentos'] ?? 0) + 1;
            $this->enCurso->guardar($datos);

            throw ValidationException::withMessages([
                'codigo' => 'El código no es correcto. Revisa el correo y vuelve a intentarlo.',
            ]);
        }

        $solicitud = DB::transaction(function () use ($datos) {
            $enRevision = SolicitudMedico::where('estado', SolicitudMedico::PENDIENTE)
                ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$datos['email']])->orWhere('cedula', $datos['cedula']))
                ->lockForUpdate()
                ->exists();

            if ($enRevision) {
                throw ValidationException::withMessages([
                    'codigo' => 'Ya tienes una solicitud en revisión. Te avisaremos por correo cuando la revisemos.',
                ]);
            }

            return SolicitudMedico::create(array_merge(
                array_intersect_key($datos, array_flip(self::CAMPOS)),
                ['estado' => SolicitudMedico::PENDIENTE],
            ));
        });

        $this->enCurso->olvidar();
        $this->correos->recibida($solicitud);

        return $solicitud;
    }
}
