<?php

namespace App\Verificacion;

use App\Models\ConfiguracionDeVerificacion;
use App\Models\User;
use App\Models\VerificacionDeMedico;

/**
 * **¿Esta cuenta puede usar la aplicación?** (2026-11-03): la única regla del corte por verificación.
 *
 *  - Un **administrador** (Root/Administrador) nunca se corta: entra al panel aunque además atienda.
 *  - Una cuenta **sin ficha de médico** (paciente, secretaría) tampoco: la verificación es del médico.
 *  - Un carnet **rechazado** corta siempre: es la marca de que el administrador encontró un problema,
 *    aunque la exigencia esté apagada.
 *  - Con `exigir_carnet` **encendido**, cualquier médico cuyo carnet no esté `verificado` se corta, incluidos
 *    los que ya estaban (decisión de Alexander, 2026-11-03): antes de encenderlo hay que verificar a los
 *    que deban seguir entrando. Con la exigencia **apagada** no se corta a nadie.
 *
 * El corte se aplica en el login del app (`LoginAppController`), en el del web (`CustomLoginController`)
 * y en las rutas clínicas y del app ya autenticadas (`ExigirVerificacionDelMedico`).
 */
final class AccesoDelMedico
{
    /** El motivo del corte, o `null` si la cuenta puede pasar. */
    public function bloqueado(User $user): ?string
    {
        if ($user->esAdministrador()) {
            return null;
        }

        $medico = $user->medico;

        if (! $medico) {
            return null;
        }

        $carnet = $medico->estadoDeVerificacion(VerificacionDeMedico::CARNET);

        if ($carnet === VerificacionDeMedico::RECHAZADO) {
            return 'El carnet que enviaste fue rechazado. Comunícate con soporte para revisarlo.';
        }

        if (! ConfiguracionDeVerificacion::actual()->exigeCarnet()) {
            return null;
        }

        if ($carnet === VerificacionDeMedico::VERIFICADO) {
            return null;
        }

        return 'Tu carnet médico todavía no está verificado. Comunícate con soporte para completar la verificación.';
    }

    public function exigeCarnet(): bool
    {
        return ConfiguracionDeVerificacion::actual()->exigeCarnet();
    }
}
