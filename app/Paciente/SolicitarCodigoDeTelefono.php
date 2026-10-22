<?php

namespace App\Paciente;

use App\Models\User;
use App\Notificaciones\Telefono;
use App\Reservas\CodigoDeVerificacion;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Primer paso de **confirmar o cambiar el teléfono** de la cuenta del paciente cuando la validación venció
 * (`identidad.telefono_validacion_meses`): se manda el código de 6 dígitos al número que escribe —el mismo de
 * siempre para confirmarlo, otro para cambiarlo— con el mismo mecanismo de la reserva y del registro
 * (`CodigoDeVerificacion`). No escribe nada en la base: el teléfono se anota al confirmar el código.
 */
final class SolicitarCodigoDeTelefono
{
    private const MINUTOS_DE_VALIDEZ = 10;
    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    public function __construct(private TelefonoPendiente $pendiente, private CodigoDeVerificacion $codigos)
    {
    }

    /**
     * @return array{reenviado: bool, canal: string}
     *
     * @throws ValidationException
     */
    public function ejecutar(User $user, string $telefono, bool $forzarReenvio = false): array
    {
        $normalizado = Telefono::internacional($telefono);

        if ($normalizado === null) {
            throw ValidationException::withMessages([
                'telefono' => 'Escribe un teléfono venezolano válido, por ejemplo 0412-1234567.',
            ]);
        }

        $anterior = $this->pendiente->datos();
        $mismoTramite = $anterior !== null
            && (int) ($anterior['user_id'] ?? 0) === (int) $user->id
            && ($anterior['telefono'] ?? null) === $normalizado
            && ($anterior['expira'] ?? 0) > now()->timestamp
            && (int) ($anterior['intentos'] ?? 0) < 5;

        if ($mismoTramite && ! $forzarReenvio) {
            return ['reenviado' => false, 'canal' => (string) ($anterior['canal'] ?? '')];
        }

        if ($mismoTramite) {
            $espera = self::SEGUNDOS_ENTRE_ENVIOS - (now()->timestamp - (int) ($anterior['enviado_en'] ?? 0));

            if ($espera > 0) {
                throw ValidationException::withMessages(['codigo' => 'Espera ' . $espera . ' segundo(s) antes de pedir otro código.']);
            }
        }

        $codigo = $this->codigos->generar();
        $envio = $this->codigos->enviar($normalizado, $codigo);

        if (! $envio['ok']) {
            throw ValidationException::withMessages([
                'telefono' => $envio['error'] ?? 'No pudimos enviar el código. Intenta de nuevo en un momento.',
            ]);
        }

        $this->pendiente->guardar([
            'user_id' => (int) $user->id,
            'telefono' => $normalizado,
            'codigo_hash' => Hash::make($codigo),
            'codigo' => app()->environment(['local', 'testing']) ? $codigo : null,
            'expira' => now()->addMinutes(self::MINUTOS_DE_VALIDEZ)->timestamp,
            'intentos' => 0,
            'enviado_en' => now()->timestamp,
            'canal' => $envio['canal'],
        ]);

        return ['reenviado' => true, 'canal' => $envio['canal']];
    }
}
