<?php

namespace App\Http\Resources;

use App\Models\Cola;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Configuración del médico que el app necesita, tomada de la tabla `evolucion` — que pese al
 * nombre **es la tabla de configuración del consultorio** en el esquema legado.
 *
 * ⚠️ **Existe justamente para no mandar la fila entera.** `evolucion` guarda en las mismas
 * columnas `clave`, `contrasena` y `sms_clave` (credenciales del médico y del proveedor de SMS).
 * Serializar el modelo directo se las mandaría al teléfono en cada sincronización. Acá se
 * enumera campo por campo lo que puede salir, y nada más.
 *
 * Los valores vienen NULL en los datos reales del legado (la configuración nunca se llenó), así
 * que cada uno cae a un default explícito en vez de dejar que el cliente adivine.
 */
class ConfiguracionMedicoResource extends JsonResource
{
    /** Con hora de cita: cada paciente tiene su hora. */
    public const MODALIDAD_HORA = 'hora_cita';

    /** Por orden de llegada: no hay hora exacta, manda el número de orden. */
    public const MODALIDAD_ORDEN = 'orden_llegada';

    public const DURACION_CITA_POR_DEFECTO = 30;

    public function toArray($request): array
    {
        return [
            'modalidad' => $this->modalidad(),
            'duracion_cita' => (int) ($this->tiempo_paci ?: self::DURACION_CITA_POR_DEFECTO),
            'pais' => $this->pais,
            // Prefijos telefónicos del consultorio: es lo que debería alimentar la normalización
            // de teléfonos en vez del +58 fijo (ver NotificacionCitaController).
            'prefijos' => array_values(array_filter([$this->prefi_1, $this->prefi_2, $this->prefi_3])),
            'correo' => $this->correo_med,
            'telefono' => $this->telefono,
            'horario' => $this->horario(),
            // Cupo de la jornada, para la modalidad por orden de llegada. Se manda tal cual,
            // **sin default**: viene NULL en los datos reales, y un tope inventado haría que el
            // app avise "el día está lleno" con un número que nadie configuró.
            'cantidad_paciente' => $this->cantidad_paciente === null
                ? null
                : (int) $this->cantidad_paciente,
            // Se manda la convención de estados para que el cliente no la tenga duplicada a mano.
            'estados_cita' => [
                'no_confirmada' => Cola::ESTADO_NO_CONFIRMADA,
                'confirmada' => Cola::ESTADO_CONFIRMADA,
                'confirmada_paciente' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            ],
        ];
    }

    /**
     * ⚠️ `cita_previa` viene **NULL en todas las filas reales** del dump legado, así que hoy esto
     * siempre cae al default. Falta confirmar con Alexander dónde se configura de verdad la
     * modalidad — hasta entonces el app no puede decidir la vista leyendo este campo.
     */
    private function modalidad(): string
    {
        return match (strtoupper((string) $this->cita_previa)) {
            'N', '0' => self::MODALIDAD_ORDEN,
            default => self::MODALIDAD_HORA,
        };
    }

    /** @return array<string, array{inicio: ?string, fin: ?string}> */
    private function horario(): array
    {
        // `vienes_i`/`domigo_f` están mal escritos en el esquema legado — se respetan tal cual
        // están en la base y se exponen con el nombre correcto.
        $dias = [
            'lunes' => ['lunes_i', 'lunes_f'],
            'martes' => ['martes_i', 'martes_f'],
            'miercoles' => ['miercoles_i', 'miercoles_f'],
            'jueves' => ['jueves_i', 'jueves_f'],
            'viernes' => ['vienes_i', 'viernes_f'],
            'sabado' => ['sabado_i', 'sabado_f'],
            'domingo' => ['domingo_i', 'domigo_f'],
        ];

        $horario = [];
        foreach ($dias as $dia => [$inicio, $fin]) {
            $horario[$dia] = ['inicio' => $this->{$inicio}, 'fin' => $this->{$fin}];
        }

        return $horario;
    }
}
