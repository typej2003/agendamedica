<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\NotificacionCita;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

/**
 * Envío de una notificación al paciente por una cita (recordatorio de cita) **desde el servidor**.
 *
 * Hoy el único canal que sale de acá es **WhatsApp**. El SMS lo manda el teléfono con el SMS nativo
 * y la línea que el médico eligió en la configuración de SIM (ver ROADMAP.md, Paso 14): no hay
 * proveedor de SMS en el servidor ni endpoint para eso. Por eso `canal: sms` se rechaza acá.
 *
 * **Por qué esto no va por la cola de sync** como el resto de las escrituras del app: mandar un
 * WhatsApp es una acción con efecto externo e irreversible. La cola de sync reintenta cuando hay
 * señal — y un reintento ciego acá le manda el mensaje dos veces al paciente. Por eso es un
 * endpoint directo, online-only: si no hay conexión, el app debe decir "no se pudo enviar" en vez
 * de encolarlo. Las escrituras de *datos* (crear cita, confirmar, cobrar) sí van por la cola.
 */
class NotificacionCitaController extends Controller
{
    private const PLANTILLA_RECORDATORIO = 'notificacion_paciente';

    public function enviar(Request $request, int $colaId)
    {
        // Solo los canales que salen del **servidor**. El SMS ya no se manda desde acá: lo manda el
        // teléfono con el SMS nativo (ver ROADMAP.md, Paso 14), así que pedirlo por este endpoint se
        // rechaza en vez de fingir un envío.
        $request->validate([
            'canal' => 'sometimes|in:whatsapp',
        ]);

        $canal = $request->input('canal', NotificacionCita::CANAL_WHATSAPP);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $registrosMedicos = MedicoRegistro::where('medico_id', $medico->id)
            ->pluck('reg_medico')
            ->filter()
            ->toArray();
        if (!empty($medico->reg_medico)) {
            $registrosMedicos[] = $medico->reg_medico;
        }
        $registrosMedicos = array_values(array_unique($registrosMedicos));

        // Mismo criterio de tenancy que `sync-app-data`: la cita tiene que ser de este médico.
        $cola = Cola::where('id', $colaId)->whereIn('reg_medico', $registrosMedicos)->first();
        if (!$cola) {
            return response()->json(['message' => 'La cita no existe o no pertenece a este médico.'], 404);
        }

        // Una cita creada desde el app para un paciente nuevo **no tiene `numhistoria`** (ese
        // número lo asigna el escritorio): en ese caso el paciente cuelga de
        // `paciente_sinhistoria_id`, que es como lo resuelve el propio esquema legado. Sin este
        // camino, mandarle el recordatorio a un paciente recién dado de alta fallaría con "la
        // cita no tiene un paciente asociado".
        if ($cola->numhistoria !== null) {
            $historia = Historia::where('numhistoria', $cola->numhistoria)
                ->whereIn('reg_medico', $registrosMedicos)
                ->first();
            $paciente = $historia?->paciente;
        } else {
            $paciente = $cola->paciente_sinhistoria_id
                ? Paciente::find($cola->paciente_sinhistoria_id)
                : null;
        }

        if (!$paciente) {
            return response()->json(['message' => 'La cita no tiene un paciente asociado.'], 422);
        }

        $destino = $this->telefonoInternacional($paciente->telefono);
        if (!$destino) {
            return response()->json([
                'message' => 'El paciente no tiene un teléfono válido registrado.',
                'telefono' => $paciente->telefono,
            ], 422);
        }

        $nombrePaciente = trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? ''));
        $mensaje = "Recordatorio de cita para {$nombrePaciente} "
            . "({$cola->fecha?->format('d/m/Y')} {$cola->hora_ini}).";

        $envio = $this->enviarPorWhatsApp($destino, $nombrePaciente);

        // El proveedor no está listo (sin credenciales, o sin implementar): no se registra nada,
        // porque no hubo intento real de envío que valga la pena guardar.
        if (isset($envio['http_status'])) {
            return response()->json(['message' => $envio['message']], $envio['http_status']);
        }

        $notificacion = NotificacionCita::create([
            'reg_medico' => $cola->reg_medico,
            'cola_id' => $cola->id,
            'canal' => $canal,
            'destino' => $destino,
            'plantilla' => self::PLANTILLA_RECORDATORIO,
            'mensaje' => $mensaje,
            'estado' => $envio['ok'] ? NotificacionCita::ESTADO_ENVIADA : NotificacionCita::ESTADO_FALLIDA,
            'respuesta' => json_encode($envio['respuesta']),
            'enviada_por' => $user->id,
        ]);

        $enviada = $envio['ok'];

        if (!$enviada) {
            return response()->json([
                'message' => 'El proveedor no aceptó el mensaje.',
                'notificacion' => $notificacion,
            ], 502);
        }

        // `sms`/`sms_text` son las columnas legadas que marcan "a esta cita ya se le avisó" —
        // se mantienen al día para que el escritorio legado vea lo mismo que el app.
        $cola->sms = '1';
        $cola->sms_text = mb_substr($notificacion->mensaje, 0, 160);
        $cola->save();

        return response()->json([
            'message' => 'Notificación enviada.',
            'notificacion' => $notificacion,
        ]);
    }

    /**
     * Un `http_status` en el resultado significa "ni siquiera se intentó": el proveedor no está
     * listo, así que el que llama corta ahí y no registra el envío.
     *
     * @return array{ok?: bool, respuesta?: array, http_status?: int, message?: string}
     */
    private function enviarPorWhatsApp(string $destino, string $nombrePaciente): array
    {
        // Se verifica antes de instanciar el servicio: su constructor tipa las credenciales como
        // `string`, así que sin configurar devolvería un 500 ilegible en vez de esto.
        if (!config('services.whatsapp.token') || !config('services.whatsapp.phone_number_id')) {
            return [
                'http_status' => 503,
                'message' => 'El envío por WhatsApp no está configurado en el servidor.',
            ];
        }

        $respuesta = app(WhatsAppService::class)->sendTemplate(
            $destino,
            self::PLANTILLA_RECORDATORIO,
            [$nombrePaciente],
        );

        return ['ok' => isset($respuesta['messages']), 'respuesta' => $respuesta];
    }

    /**
     * Normaliza a formato internacional sin `+`, que es lo que pide la API de Meta.
     *
     * TODO: parametrizar el código de país por médico — el legado ya lo guarda en `evolucion`
     *       (`pais`, `prefi_1`, `prefi_2`, `prefi_3`), y el proyecto contempla multi-país.
     *
     * ⚠️ Asume **Venezuela (+58)** para los números locales, que es como están guardados los
     * teléfonos del legado (`04121234567`). El proyecto contempla multi-país
     * (Docs/Wiki/00-contexto-negocio.md), así que esto hay que parametrizarlo por médico/país
     * antes de vender fuera de Venezuela — queda anotado en el ROADMAP.
     */
    private function telefonoInternacional(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);

        if ($digitos === '') {
            return null;
        }

        // Ya viene internacional: 58 + operadora (3) + número (7).
        if (str_starts_with($digitos, '58') && strlen($digitos) === 12) {
            return $digitos;
        }

        // Local con 0 inicial: 0412 1234567.
        if (str_starts_with($digitos, '0') && strlen($digitos) === 11) {
            return '58' . substr($digitos, 1);
        }

        // Local sin 0: 412 1234567.
        if (strlen($digitos) === 10 && str_starts_with($digitos, '4')) {
            return '58' . $digitos;
        }

        // Cualquier otra cosa no se adivina: mejor que el médico corrija el teléfono a que el
        // mensaje se vaya a un número equivocado.
        return null;
    }
}
