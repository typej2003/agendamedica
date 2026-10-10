<?php

namespace App\Actions\Cumpleanos;

use App\Cumpleanos\Cumpleano;
use App\Cumpleanos\MensajeDeCumpleanos;
use App\Cumpleanos\RegistroDeCumpleanos;
use App\Mail\SaludoDeCumpleanos;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\NotificacionCumple;
use App\Models\User;
use App\Notificaciones\Canal;
use App\Notificaciones\Correo;
use App\Notificaciones\DespachadorDeCanales;
use App\Notificaciones\ExcepcionDeEnvio;
use App\Notificaciones\Telefono;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Mandarle el saludo de cumpleaños a un paciente (WEB-2.9), por cualquiera de los tres canales.
 *
 * Es **una sola** Action para las dos superficies nuevas: la pantalla la llama cuando alguien aprieta
 * "Enviar" en una fila y el comando `cumpleanos:enviar` la llama para el envío automático. La parte de
 * los canales no se escribe acá: es `App\Notificaciones\DespachadorDeCanales`, el mismo que usa el
 * recordatorio de cita.
 *
 * Tres reglas que sí son del saludo:
 *
 *  1. **El mensaje es el de la plantilla del médico del `reg_medico`**, no el de quien tiene la sesión
 *     abierta: una secretaría puede estar cargando saludos de un médico que no es el suyo, y además el
 *     automático no tiene sesión. La fila de `evolucion` se resuelve por el `reg_medico` del contexto
 *     (es la misma que edita la pantalla de cumpleaños).
 *  2. **Todo intento queda registrado** en `notificaciones_cumple`, con la **fecha del cumpleaños
 *     saludado** (`fecha_cumple`): es lo que hace que el comando pueda correr cada pocos minutos sin
 *     mandar dos veces el mismo saludo (`RegistroDeCumpleanos`).
 *  3. **No pasa por la cola de sync**, igual que el recordatorio: un envío es irreversible y un
 *     reintento ciego le mandaría el saludo dos veces al paciente. El comando corre en el servidor, así
 *     que esto sale solo.
 *
 * ⚠️ Es la misma limitación de WhatsApp que el recordatorio: viaja la plantilla aprobada por Meta
 * (`notificacion_paciente`), no el texto del médico. Por eso el **canal por defecto es el correo**
 * (`ConfiguracionDeCumpleanos`): un envío que nadie mira no puede salir por un canal que no lleva el
 * mensaje que el médico escribió.
 */
final class EnviarSaludoDeCumpleanos
{
    public function __construct(
        private MensajeDeCumpleanos $mensajes,
        private DespachadorDeCanales $canales,
        private RegistroDeCumpleanos $registro,
    ) {
    }

    /**
     * @param  string  $canal  Uno de `App\Notificaciones\Canal`.
     * @param  string|null  $mensaje  Texto a mandar; si viene vacío se arma con la plantilla del médico.
     * @param  User|null  $usuario  Quién lo mandó; `null` cuando lo manda el servidor solo (el comando).
     * @param  bool  $forzar  Reenviar aunque ya se le haya mandado el saludo de hoy. El automático
     *                        **nunca** lo usa; es la decisión explícita de una persona en la pantalla.
     *
     * @throws ExcepcionDeEnvio  si al paciente le falta el dato del canal o el proveedor no está configurado.
     */
    public function ejecutar(
        Cumpleano $cumpleano,
        string $regMedico,
        string $canal,
        ?string $mensaje = null,
        ?User $usuario = null,
        bool $forzar = false
    ): NotificacionCumple {
        if (! Canal::existe($canal)) {
            throw new InvalidArgumentException("Canal de notificación desconocido: {$canal}.");
        }

        // Si ya se le mandó el saludo de este cumpleaños, se devuelve **lo que pasó** en vez de mandarlo
        // otra vez: es lo que protege al envío automático de dos corridas solapadas, y lo que hace que un
        // doble clic en la pantalla no le mande dos mensajes al paciente. El índice único de la tabla es
        // la red de seguridad de la carrera más fina (entre esta consulta y el `insert`).
        if (! $forzar) {
            $yaMandado = $this->registro->envioDe($cumpleano->pacienteId(), $cumpleano->dia());

            if ($yaMandado !== null) {
                return $yaMandado;
            }
        }

        try {
            return $this->mandar($cumpleano, $regMedico, $canal, $mensaje, $usuario);
        } catch (QueryException $e) {
            if (! RegistroDeCumpleanos::esChoqueDeEnvioUnico($e)) {
                throw $e;
            }

            // Otra corrida ganó la carrera: el saludo de hoy ya está registrado y no se manda de nuevo.
            return $this->registro->envioDe($cumpleano->pacienteId(), $cumpleano->dia())
                ?? NotificacionCumple::make([
                    'paciente_id'  => $cumpleano->pacienteId(),
                    'fecha_cumple' => $cumpleano->fecha(),
                    'canal'        => $canal,
                    'estado'       => NotificacionCumple::ESTADO_ENVIADA,
                ]);
        }
    }

    /** El envío en sí, con su registro. */
    private function mandar(
        Cumpleano $cumpleano,
        string $regMedico,
        string $canal,
        ?string $mensaje,
        ?User $usuario
    ): NotificacionCumple {
        $evolucion = Evolucion::where('reg_medico', $regMedico)->first();
        $medico = $this->medicoDelRegistro($regMedico, $evolucion);

        $texto = $this->texto($mensaje, $evolucion?->plantilla_cumple, $cumpleano, $medico);
        $destino = $this->destino($canal, $cumpleano);

        $envio = $this->canales->enviar(
            $canal,
            $destino,
            $texto,
            $cumpleano->nombreParaElSaludo(),
            $medico,
            null,
            SaludoDeCumpleanos::class,
        );

        return NotificacionCumple::create([
            'reg_medico'  => $regMedico,
            'paciente_id' => $cumpleano->pacienteId(),
            'fecha_cumple' => $cumpleano->fecha(),
            'canal'       => $canal,
            'destino'     => $destino,
            'plantilla'   => $canal === Canal::WHATSAPP
                ? DespachadorDeCanales::PLANTILLA_WHATSAPP
                : (trim((string) $evolucion?->plantilla_cumple) !== '' ? 'plantilla_cumple' : 'saludo_por_defecto'),
            'mensaje'     => mb_substr($texto, 0, 500),
            'estado'      => $envio['ok'] ? NotificacionCumple::ESTADO_ENVIADA : NotificacionCumple::ESTADO_FALLIDA,
            'respuesta'   => json_encode($envio['respuesta']),
            'enviada_por' => $usuario?->id,
        ]);
    }

    /**
     * El médico del registro: el que la fila de `evolucion` identifica por `correo_med`, y si no hay
     * configuración cargada —lo común en los datos reales— el único médico del registro. Es el mismo
     * camino que usa el recordatorio de cita, y sirve para firmar el correo y para el `{doctor}`.
     */
    private function medicoDelRegistro(string $regMedico, ?Evolucion $evolucion): ?Medico
    {
        $medico = $evolucion?->correo_med
            ? Medico::where('email', $evolucion->correo_med)->first()
            : null;

        return $medico ?: Medico::where('reg_medico', $regMedico)->first();
    }

    /** El texto que se manda: el que escribió el usuario, o la plantilla del médico ya sustituida. */
    private function texto(?string $mensaje, ?string $plantilla, Cumpleano $cumpleano, ?Medico $medico): string
    {
        $mensaje = trim((string) $mensaje);

        if ($mensaje !== '') {
            return mb_substr($mensaje, 0, 500);
        }

        return $this->mensajes->armar($plantilla, [
            'paciente' => $cumpleano->nombreParaElSaludo(),
            'doctor'   => $medico?->nombreMostrar ?? '',
        ]);
    }

    /** A dónde va el saludo: el teléfono o el correo del paciente, según el canal. */
    private function destino(string $canal, Cumpleano $cumpleano): string
    {
        if (Canal::usaCorreo($canal)) {
            $correo = Correo::valido($cumpleano->correo());

            if ($correo === null) {
                throw new ExcepcionDeEnvio($cumpleano->mensajeDeDatoFaltante($canal));
            }

            return $correo;
        }

        $telefono = Telefono::internacional($cumpleano->telefono());

        if ($telefono === null) {
            throw new ExcepcionDeEnvio($cumpleano->mensajeDeDatoFaltante($canal));
        }

        return $telefono;
    }
}
