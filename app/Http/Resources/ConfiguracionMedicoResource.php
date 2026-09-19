<?php

namespace App\Http\Resources;

use App\Models\Cola;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Configuración **del médico** que el app necesita, tomada de la tabla `evolucion`.
 *
 * ⚠️ Lo que es **de la sede ya no sale de acá**: la modalidad de agenda, la duración de la
 * consulta, el horario y el cupo de la jornada se mudaron a `offices` / `office_schedules`,
 * porque un médico atiende en varios lugares y cada uno trabaja distinto (hospital por orden de
 * llegada en la mañana, clínica con hora de cita en la tarde). `evolucion` solo sabe de un
 * horario por médico, así que no podía expresarlo. Acá quedan los datos que sí son de la persona:
 * país, prefijos telefónicos, correo y teléfono.
 *
 * ⚠️ **Existe justamente para no mandar la fila entera.** `evolucion` guarda en las mismas
 * columnas `clave`, `contrasena` y `sms_clave` (credenciales del médico y del proveedor de SMS).
 * Serializar el modelo directo se las mandaría al teléfono en cada sincronización. Acá se
 * enumera campo por campo lo que puede salir, y nada más.
 *
 * Los valores vienen NULL en los datos reales del legado (la configuración nunca se llenó).
 */
class ConfiguracionMedicoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'pais' => $this->pais,
            // Prefijos telefónicos del consultorio: es lo que debería alimentar la normalización
            // de teléfonos en vez del +58 fijo (ver NotificacionCitaController).
            'prefijos' => array_values(array_filter([$this->prefi_1, $this->prefi_2, $this->prefi_3])),
            'correo' => $this->correo_med,
            'telefono' => $this->telefono,
            // Se manda la convención de estados para que el cliente no la tenga duplicada a mano.
            'estados_cita' => [
                'no_confirmada' => Cola::ESTADO_NO_CONFIRMADA,
                'confirmada' => Cola::ESTADO_CONFIRMADA,
                'confirmada_paciente' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            ],
        ];
    }
}
