<?php

namespace App\RegistroDeMedicos;

use App\Models\SolicitudMedico;
use App\Referidos\CodigosDeReferido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Segundo paso del **registro de médicos**: comprobar el código del correo y **guardar la solicitud**, que
 * queda pendiente hasta que un administrador la revise. No crea cuenta: el médico no puede entrar todavía.
 *
 * Revalida que la cédula no esté en otra solicitud (dos pestañas pueden llegar juntas) y, guardada, avisa al
 * médico y a los administradores. Si el correo ya tenía una solicitud **pendiente**, se **actualiza** (la misma
 * persona corrigiendo sus datos) en vez de crear otra.
 */
final class ConfirmarSolicitudDeMedico
{
    private const INTENTOS_MAXIMOS = 5;

    private const CAMPOS = [
        'prefijo', 'nombres', 'apellidos', 'cedula', 'mpps', 'colegio', 'specialty_id', 'telefono', 'email',
        'ciudad', 'mensaje', 'carnet_ruta', 'carnet_tipo', 'carnet_nombre', 'codigo_referido',
        'referente_reg_medico',
    ];

    public function __construct(
        private SolicitudEnCurso $enCurso,
        private CorreosDeLaSolicitud $correos,
        private CodigosDeReferido $codigosDeReferido,
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

        // Se resuelve una sola vez, al confirmar: si el código no existe, la solicitud se guarda sin
        // atribución y no se bloquea el registro.
        $referido = $this->codigosDeReferido->resolver($datos['codigo_referido'] ?? null);
        $datos['codigo_referido'] = $referido?->codigo;
        $datos['referente_reg_medico'] = $referido?->reg_medico;

        if (! Hash::check($codigo, (string) ($datos['codigo_hash'] ?? ''))) {
            $datos['intentos'] = (int) ($datos['intentos'] ?? 0) + 1;
            $this->enCurso->guardar($datos);

            throw ValidationException::withMessages([
                'codigo' => 'El código no es correcto. Revisa el correo y vuelve a intentarlo.',
            ]);
        }

        $solicitud = DB::transaction(function () use ($datos) {
            $campos = array_intersect_key($datos, array_flip(self::CAMPOS));

            $pendiente = SolicitudMedico::where('estado', SolicitudMedico::PENDIENTE)
                ->whereRaw('LOWER(email) = ?', [$datos['email']])
                ->lockForUpdate()
                ->first();

            $cedulaDeOtra = SolicitudMedico::where('estado', SolicitudMedico::PENDIENTE)
                ->where('cedula', $datos['cedula'])
                ->when($pendiente !== null, fn ($query) => $query->where('id', '!=', $pendiente->id))
                ->exists();

            if ($cedulaDeOtra) {
                throw ValidationException::withMessages([
                    'codigo' => 'Esa cédula ya tiene una solicitud en revisión con otro correo.',
                ]);
            }

            if ($pendiente !== null) {
                // Retomar: es la misma persona (verificó el mismo correo) y está corrigiendo su solicitud. Se
                // actualiza la que ya estaba pendiente en vez de crear una segunda, que la bandeja del
                // administrador mostraría duplicada (2026-10-24). La foto anterior se conserva si no subió otra.
                if (empty($campos['carnet_ruta'])) {
                    unset($campos['carnet_ruta'], $campos['carnet_tipo'], $campos['carnet_nombre']);
                }

                $pendiente->update($campos);

                return $pendiente;
            }

            return SolicitudMedico::create(array_merge($campos, ['estado' => SolicitudMedico::PENDIENTE]));
        });

        $this->enCurso->olvidar();
        $this->correos->recibida($solicitud);

        return $solicitud;
    }
}
