<?php

namespace App\Reservas;

use App\Clinica\FechaDelConsultorio;
use App\Mail\AvisoDeUrgenciaParaSecretaria;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\Office;
use App\Models\Paciente;
use App\Services\CuentaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * **Urgencia afirmada por Jev y sin horario hoy** (a partir de ahora + 90 min): el bot no se queda en "no hay":
 * le da a la paciente el **teléfono del consultorio** y le **avisa a la secretaría**, porque puede haber lugar
 * que el sistema no refleja (alguien que no llegó, consultas más cortas de lo previsto). Se SUMA a la oferta
 * de agenda de otros días; nunca la reemplaza ni promete atención.
 *
 * **Contacto** (el del tenant `reg_medico` de la conversación; nunca se inventa uno): el teléfono del consultorio
 * (`evolucion.telefono`), si no hay el de una sede activa del médico (`offices.phone`) y, si tampoco, el del
 * médico (`medicos.phone`). Sin ninguno no hay aviso ni mención de contacto: sólo se registra en el log.
 *
 * **Canal del aviso: correo** (SMTP del servidor) a las cuentas de **secretaría** vinculadas al `reg_medico`
 * (`CuentaService::secretariasDe`); si el consultorio no tiene secretaría con correo, al correo del consultorio
 * (`evolucion.correo_med`, luego `medicos.email`). No va por WhatsApp porque la secretaría está fuera de la
 * ventana de 24 h y Meta sólo admite plantillas aprobadas (la única, `notificacion_paciente`, es para
 * pacientes). El aviso lleva la paciente (nombre de la ficha y teléfono) y la hora del mensaje, **sin el texto
 * del mensaje ni datos clínicos**.
 *
 * **Anti-ruido**: un aviso por teléfono cada `services.chatbot.urgencia_aviso_minutos` (60 por defecto). Dentro de
 * esa ventana la paciente recibe igual el contacto, sin re-aviso. Si el envío falla, el candado se suelta para
 * que el siguiente mensaje reintente, y la respuesta a la paciente sale igual.
 */
class AvisoDeUrgenciaSinHorario
{
    /**
     * @return string|null el texto para la paciente (con el contacto), o null si no hay contacto configurado
     */
    public function atender(Medico $medico, string $regMedico, string $telefono, ?Paciente $paciente, string $referencia): ?string
    {
        $contacto = null;
        $avisada = false;

        try {
            $contacto = $this->telefonoDelConsultorio($medico, $regMedico);

            if ($contacto === null) {
                $this->registrar('omitido', 'sin_contacto', $referencia);

                return null;
            }

            $avisada = $this->avisar($medico, $regMedico, $telefono, $paciente, $referencia);
        } catch (Throwable $e) {
            // Nada de esto puede romper la respuesta a la paciente.
            $this->registrar('fallido', 'error_' . class_basename($e), $referencia);
        }

        return $contacto === null ? null : self::texto($contacto, $avisada);
    }

    /** El texto de la paciente: tuteo, sin prometer atención ni horario. */
    public static function texto(string $contacto, bool $seAvisoALaSecretaria): string
    {
        return '🚨 Entiendo que es urgente. Hoy no veo horarios libres, pero puede haber lugar que el sistema no refleja. '
            . ($seAvisoALaSecretaria
                ? "Comunícate con el consultorio al *{$contacto}* y avisamos a la secretaría para que te ayude."
                : "Comunícate con el consultorio al *{$contacto}* para que te ayuden.");
    }

    /** ¿La secretaría está avisada (ahora o dentro de la ventana anti-ruido)? */
    private function avisar(Medico $medico, string $regMedico, string $telefono, ?Paciente $paciente, string $referencia): bool
    {
        $minutos = max(1, (int) config('services.chatbot.urgencia_aviso_minutos', 60));
        $clave = 'wa_urgencia_aviso_' . sha1(preg_replace('/\D/', '', $telefono) ?: $telefono);

        // `add` es atómico: el primero que llega se queda con el candado.
        if (! Cache::add($clave, 1, now()->addMinutes($minutos))) {
            $this->registrar('omitido', 'ya_avisada', $referencia);

            return true;
        }

        $destinos = $this->destinatarios($medico, $regMedico);
        if ($destinos === []) {
            Cache::forget($clave);
            $this->registrar('omitido', 'sin_destinatarios', $referencia);

            return false;
        }

        try {
            Mail::to($destinos)->send(new AvisoDeUrgenciaParaSecretaria(
                paciente: $paciente ? trim("{$paciente->nombres} {$paciente->apellidos}") : null,
                telefono: $telefono,
                hora: FechaDelConsultorio::ahora()->format('d/m/Y H:i'),
                medico: $medico->nombre_mostrar,
            ));
        } catch (Throwable $e) {
            Cache::forget($clave);
            $this->registrar('fallido', 'error_' . class_basename($e), $referencia);

            return false;
        }

        $this->registrar('enviado', 'correo', $referencia, count($destinos));

        return true;
    }

    /** Teléfono del consultorio de ese `reg_medico`, o null. Nunca un valor de relleno como «No especificado». */
    public function telefonoDelConsultorio(Medico $medico, string $regMedico): ?string
    {
        $candidatos = [
            Evolucion::where('reg_medico', $regMedico)->value('telefono'),
            Office::where('reg_medico', $regMedico)->where('activo', true)->whereNotNull('phone')->orderBy('id')->value('phone'),
            $medico->phone,
        ];

        foreach ($candidatos as $candidato) {
            $texto = trim((string) $candidato);
            if (strlen(preg_replace('/\D/', '', $texto) ?? '') >= 7) {
                return $texto;
            }
        }

        return null;
    }

    /**
     * Las secretarias activas del consultorio; si no hay ninguna con correo, el correo del consultorio.
     *
     * @return list<string>
     */
    private function destinatarios(Medico $medico, string $regMedico): array
    {
        $secretarias = app(CuentaService::class)->secretariasDe([$regMedico])
            ->filter(fn ($user) => $user->is_active !== false && empty($user->blocked_reason))
            ->pluck('email')
            ->all();

        $validos = $this->soloCorreosValidos($secretarias);

        if ($validos !== []) {
            return $validos;
        }

        $respaldo = $this->soloCorreosValidos([
            Evolucion::where('reg_medico', $regMedico)->value('correo_med'),
            $medico->email,
        ]);

        return array_slice($respaldo, 0, 1);
    }

    /**
     * @param  array<int,mixed>  $correos
     * @return list<string>
     */
    private function soloCorreosValidos(array $correos): array
    {
        $validos = [];
        foreach ($correos as $correo) {
            $correo = trim((string) $correo);
            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)
                && ! in_array(strtolower($correo), array_map('strtolower', $validos), true)) {
                $validos[] = $correo;
            }
        }

        return $validos;
    }

    private function registrar(string $resultado, string $motivo, string $referencia, int $destinatarios = 0): void
    {
        Log::info('Chatbot: aviso de urgencia a la secretaría ' . $resultado, [
            'paciente' => $referencia,
            'resultado' => $resultado,
            'motivo' => $motivo,
            'destinatarios' => $destinatarios,
        ]);
    }
}
