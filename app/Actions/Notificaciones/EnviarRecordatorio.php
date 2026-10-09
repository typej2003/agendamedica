<?php

namespace App\Actions\Notificaciones;

use App\Mail\RecordatorioDeCita;
use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\NotificacionCita;
use App\Models\Paciente;
use App\Models\User;
use App\Notificaciones\Canal;
use App\Notificaciones\Correo;
use App\Notificaciones\ExcepcionDeEnvio;
use App\Notificaciones\MensajeDeRecordatorio;
use App\Notificaciones\Telefono;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use App\Support\FechaClinica;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Mandar el recordatorio de una cita al paciente, por cualquiera de los tres canales (WEB-2.6).
 *
 * Es **una sola** Action para las dos superficies (PLAN-WEB.md, R3): la web la llama en proceso y
 * `POST /app/citas/{cola}/notificar` la usa para el móvil. Antes esa lógica vivía dentro del
 * controlador del API, que además rechazaba `canal: sms` porque no había proveedor; ahora el servidor
 * sí manda SMS (Twilio) y el canal es una decisión del que llama, no una limitación escondida.
 *
 * Tres reglas que no se negocian (wiki `03-notificaciones-recordatorios.md`):
 *
 *  1. **No pasa por la cola de sync.** Un envío es irreversible; la cola reintenta al volver la señal
 *     y un reintento ciego le manda el mensaje dos veces al paciente. Por eso el endpoint es
 *     online-only y la web manda en el request.
 *  2. **La plantilla es la del médico de la cita** (`cola.medico` → `evolucion.clave`), no la de quien
 *     tiene la sesión abierta: una secretaría manda recordatorios de varios médicos y cada uno va con
 *     sus palabras.
 *  3. **Todo intento queda registrado** en `notificaciones_cita` (canal, destino, mensaje, estado,
 *     respuesta del proveedor y quién lo mandó), y el envío que salió deja la constancia que el
 *     escritorio entiende en `cola.sms_text`.
 *
 * ⚠️ **WhatsApp**: lo que viaja es la plantilla aprobada por Meta (`notificacion_paciente`, con el
 * nombre del paciente como único parámetro). El texto del médico se registra como el mensaje del
 * envío, pero no es lo que se transmite: fuera de la ventana de 24 h Meta solo acepta plantillas
 * aprobadas. Es una limitación del canal, no una decisión de producto.
 */
final class EnviarRecordatorio
{
    /** Nombre exacto de la plantilla aprobada en Meta, la única que se puede mandar por WhatsApp. */
    public const PLANTILLA_WHATSAPP = 'notificacion_paciente';

    public function __construct(
        private MensajeDeRecordatorio $mensajes,
    ) {
    }

    /**
     * @param  string  $canal  Uno de `App\Notificaciones\Canal`.
     * @param  string|null  $mensaje  Texto a mandar; si viene vacío se arma con la plantilla del médico.
     *
     * @throws ExcepcionDeEnvio  si al paciente le falta el dato del canal o el proveedor no está configurado.
     */
    public function ejecutar(Cola $cita, string $canal, ?string $mensaje = null, ?User $usuario = null): NotificacionCita
    {
        if (! Canal::existe($canal)) {
            throw new InvalidArgumentException("Canal de notificación desconocido: {$canal}.");
        }

        $paciente = $this->pacienteDeLaCita($cita);
        if (! $paciente) {
            throw new ExcepcionDeEnvio('La cita no tiene un paciente asociado.');
        }

        [$medico, $plantilla] = $this->medicoYPlantilla($cita);
        $centro = $this->centroDeLaCita($cita);

        $texto = $this->texto($mensaje, $plantilla, $cita, $paciente, $medico, $centro);
        $destino = $this->destino($canal, $paciente);

        $envio = $this->enviar($canal, $destino, $texto, $paciente, $medico, $centro);

        $notificacion = NotificacionCita::create([
            'reg_medico' => $cita->reg_medico,
            'cola_id'    => $cita->id,
            'canal'      => $canal,
            'destino'    => $destino,
            'plantilla'  => $canal === Canal::WHATSAPP
                ? self::PLANTILLA_WHATSAPP
                : (trim((string) $plantilla) !== '' ? 'plantilla_cita' : 'recordatorio_por_defecto'),
            'mensaje'    => mb_substr($texto, 0, 500),
            'estado'     => $envio['ok'] ? NotificacionCita::ESTADO_ENVIADA : NotificacionCita::ESTADO_FALLIDA,
            'respuesta'  => json_encode($envio['respuesta']),
            'enviada_por' => $usuario?->id,
        ]);

        if ($envio['ok']) {
            // `sms`/`sms_text` son las columnas legadas con las que el escritorio ve "a esta cita ya
            // se le avisó". Se escribe **solo `sms_text`** (la constancia): `cola.sms` es, en el
            // escritorio, el tilde con el que el usuario elige destinatarios del envío masivo
            // (`checkbox.on="S"` en `d_pacientes_citas_sms.srd`), no un estado de "enviado" — el
            // endpoint viejo escribía ahí un `1` que no significa nada para el legado.
            $cita->sms_text = mb_substr($texto, 0, 160);
            $cita->save();
        }

        return $notificacion;
    }

    /**
     * La ficha del paciente de la cita: por `paciente_sinhistoria_id` —una cita creada desde el app
     * para alguien que todavía no tiene número de historia— o por el vínculo `medico_pacientes`, con
     * la historia como segundo camino para los datos que llegaron del escritorio.
     *
     * Es público porque la web lo necesita para decir **a quién** no se le pudo mandar el envío del
     * día (`AgendaController::enviarMasivo`), sin resolver el paciente por segunda vez.
     */
    public function pacienteDeLaCita(Cola $cita): ?Paciente
    {
        if ($cita->paciente_sinhistoria_id !== null) {
            return Paciente::find($cita->paciente_sinhistoria_id);
        }

        if ($cita->numhistoria === null) {
            return null;
        }

        $pacienteId = MedicoPaciente::where('reg_medico', $cita->reg_medico)
            ->where('numhistoria', $cita->numhistoria)
            ->value('paciente_id');

        if ($pacienteId !== null) {
            return Paciente::find($pacienteId);
        }

        return Historia::where('reg_medico', $cita->reg_medico)
            ->where('numhistoria', $cita->numhistoria)
            ->first()?->paciente;
    }

    /**
     * El médico **de la cita** y su plantilla, resueltos por `evolucion.clave` = `cola.medico`.
     *
     * Si el consultorio no tiene la configuración cargada (lo común en los datos reales) se cae al
     * médico del registro, que es el único del tenant: sirve para firmar el correo y para el `{doctor}`.
     *
     * @return array{0: ?Medico, 1: ?string}
     */
    private function medicoYPlantilla(Cola $cita): array
    {
        $evolucion = $cita->medico !== null
            ? Evolucion::where('reg_medico', $cita->reg_medico)->where('clave', $cita->medico)->first()
            : null;

        $medico = $evolucion?->correo_med
            ? Medico::where('email', $evolucion->correo_med)->first()
            : null;

        $medico = $medico ?: Medico::where('reg_medico', $cita->reg_medico)->first();

        return [$medico, $evolucion?->plantilla_cita];
    }

    /** El nombre del centro donde se atiende la cita, para la etiqueta `{centro}`. */
    private function centroDeLaCita(Cola $cita): ?string
    {
        $centroId = $cita->medical_center_id;

        if ($centroId === null && $cita->numhistoria !== null) {
            $centroId = Historia::where('reg_medico', $cita->reg_medico)
                ->where('numhistoria', $cita->numhistoria)
                ->value('medical_center_id');
        }

        return $centroId !== null ? MedicalCenter::where('id', $centroId)->value('name') : null;
    }

    /** El texto que se manda: el que escribió el usuario, o la plantilla del médico ya sustituida. */
    private function texto(
        ?string $mensaje,
        ?string $plantilla,
        Cola $cita,
        Paciente $paciente,
        ?Medico $medico,
        ?string $centro
    ): string {
        $mensaje = trim((string) $mensaje);

        if ($mensaje !== '') {
            return mb_substr($mensaje, 0, 500);
        }

        return $this->mensajes->armar($plantilla, [
            'paciente' => $this->nombreDelPaciente($paciente),
            'fecha'    => $this->fechaCorta($cita->fecha),
            'hora'     => $cita->hora_ini ? substr((string) $cita->hora_ini, 0, 5) : '',
            'doctor'   => $this->nombreDelMedico($medico),
            'centro'   => (string) $centro,
        ]);
    }

    /** A dónde va el mensaje: el teléfono o el correo del paciente, según el canal. */
    private function destino(string $canal, Paciente $paciente): string
    {
        if (Canal::usaCorreo($canal)) {
            $correo = Correo::valido($paciente->email);

            if ($correo === null) {
                throw new ExcepcionDeEnvio('El paciente no tiene un correo válido registrado.');
            }

            return $correo;
        }

        $telefono = Telefono::internacional($paciente->telefono);

        if ($telefono === null) {
            throw new ExcepcionDeEnvio('El paciente no tiene un teléfono válido registrado.');
        }

        return $telefono;
    }

    /**
     * @return array{ok: bool, respuesta: array}
     *
     * @throws ExcepcionDeEnvio  cuando al canal le falta la configuración del servidor.
     */
    private function enviar(
        string $canal,
        string $destino,
        string $texto,
        Paciente $paciente,
        ?Medico $medico,
        ?string $centro
    ): array {
        return match ($canal) {
            Canal::WHATSAPP => $this->porWhatsApp($destino, $paciente),
            Canal::SMS      => $this->porSms($destino, $texto),
            Canal::CORREO   => $this->porCorreo($destino, $texto, $paciente, $medico, $centro),
        };
    }

    /** @return array{ok: bool, respuesta: array} */
    private function porWhatsApp(string $destino, Paciente $paciente): array
    {
        // Se verifica la configuración **antes de instanciar** el servicio: `WhatsAppService` tipa sus
        // credenciales como `string`, así que sin `WHATSAPP_TOKEN` en el entorno su constructor tira
        // un `TypeError` en vez del 503 que corresponde.
        if (! config('services.whatsapp.token') || ! config('services.whatsapp.phone_number_id')) {
            throw new ExcepcionDeEnvio('El envío por WhatsApp no está configurado en el servidor.', 503);
        }

        $respuesta = app(WhatsAppService::class)->sendTemplate(
            $destino,
            self::PLANTILLA_WHATSAPP,
            [$this->nombreDelPaciente($paciente)],
        );

        return ['ok' => isset($respuesta['messages']), 'respuesta' => $respuesta];
    }

    /** @return array{ok: bool, respuesta: array} */
    private function porSms(string $destino, string $texto): array
    {
        $sms = app(SmsService::class);

        if (! $sms->configurado()) {
            throw new ExcepcionDeEnvio('El envío de SMS no está configurado en el servidor.', 503);
        }

        return $sms->enviar($destino, $texto);
    }

    /** @return array{ok: bool, respuesta: array} */
    private function porCorreo(string $destino, string $texto, Paciente $paciente, ?Medico $medico, ?string $centro): array
    {
        $remitente = Correo::valido($medico->email ?? null);
        $nombre = $this->nombreDelMedico($medico);

        try {
            Mail::to($destino)->send(new RecordatorioDeCita(
                cuerpo: $texto,
                paciente: $this->nombreDelPaciente($paciente),
                doctor: $nombre !== '' ? $nombre : null,
                centro: $centro,
                remitente: $remitente,
                nombreRemitente: $nombre !== '' ? $nombre : null,
            ));
        } catch (\Throwable $e) {
            return ['ok' => false, 'respuesta' => ['error' => $e->getMessage()]];
        }

        return ['ok' => true, 'respuesta' => ['to' => $destino, 'from' => $remitente]];
    }

    private function nombreDelPaciente(Paciente $paciente): string
    {
        return trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? '')) ?: 'paciente';
    }

    /** `Dr. Carlos Mendoza`, el mismo texto que el móvil (`Medico::nombreMostrar`). */
    private function nombreDelMedico(?Medico $medico): string
    {
        return $medico?->nombreMostrar ?? '';
    }

    /** La fecha del legado puede ser basura: la que no se puede leer va vacía, no rompe el mensaje. */
    private function fechaCorta($fecha): string
    {
        $texto = FechaClinica::formato($fecha, 'd/m/Y');

        return $texto === '—' ? '' : $texto;
    }
}
