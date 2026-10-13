<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notificaciones\EnviarRecordatorio;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\NotificacionCita;
use App\Notificaciones\Canal;
use App\Notificaciones\ExcepcionDeEnvio;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Envío de una notificación al paciente por una cita (recordatorio de cita) desde el **servidor**.
 *
 * El canal lo elige el cliente y todos salen de acá, menos el SMS nativo del móvil, que lo manda el
 * teléfono con la línea que el médico eligió en la configuración de SIM (ver ROADMAP.md, Paso 14) y
 * no pasa por el servidor. La lógica del envío vive en `App\Actions\Notificaciones\EnviarRecordatorio`,
 * que es la misma que usa la web clínica (PLAN-WEB.md, R3): acá solo queda la autenticación, el
 * **tenancy** (la cita tiene que ser de este médico) y traducir el resultado a HTTP.
 *
 * **Por qué esto no va por la cola de sync** como el resto de las escrituras del app: mandar un
 * mensaje es una acción con efecto externo e irreversible. La cola de sync reintenta cuando hay
 * señal — y un reintento ciego acá le manda el mensaje dos veces al paciente. Por eso es un
 * endpoint directo, online-only: si no hay conexión, el app debe decir "no se pudo enviar" en vez
 * de encolarlo. Las escrituras de *datos* (crear cita, confirmar, cobrar) sí van por la cola.
 */
class NotificacionCitaController extends Controller
{
    public function enviar(Request $request, int $colaId, EnviarRecordatorio $accion)
    {
        $request->validate([
            'canal'   => 'sometimes|in:' . implode(',', Canal::todos()),
            'mensaje' => 'sometimes|nullable|string|max:500',
        ]);

        $canal = $request->input('canal', NotificacionCita::CANAL_WHATSAPP);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (! $medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $registrosMedicos = MedicoRegistro::where('medico_id', $medico->id)
            ->pluck('reg_medico')
            ->filter()
            ->toArray();
        if (! empty($medico->reg_medico)) {
            $registrosMedicos[] = $medico->reg_medico;
        }
        $registrosMedicos = array_values(array_unique($registrosMedicos));

        // Mismo criterio de tenancy que `sync-app-data`: la cita tiene que ser de este médico.
        $cola = Cola::where('id', $colaId)->whereIn('reg_medico', $registrosMedicos)->first();
        if (! $cola) {
            return response()->json(['message' => 'La cita no existe o no pertenece a este médico.'], 404);
        }

        try {
            $notificacion = $accion->ejecutar($cola, $canal, $request->input('mensaje'), $user);
        } catch (ExcepcionDeEnvio $excepcion) {
            // Ni siquiera se intentó: falta el dato del paciente o el proveedor no está configurado.
            return response()->json(['message' => $excepcion->getMessage()], $excepcion->estadoHttp);
        } catch (InvalidArgumentException $excepcion) {
            return response()->json(['message' => $excepcion->getMessage()], 422);
        }

        if ($notificacion->estado !== NotificacionCita::ESTADO_ENVIADA) {
            return response()->json([
                'message' => 'El proveedor no aceptó el mensaje.',
                'notificacion' => $notificacion,
            ], 502);
        }

        return response()->json([
            'message' => 'Notificación enviada.',
            'notificacion' => $notificacion,
        ]);
    }
}
