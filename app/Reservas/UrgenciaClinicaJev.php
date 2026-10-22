<?php

namespace App\Reservas;

use App\Services\JevService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ¿El mensaje de WhatsApp de quien pide una cita describe una **urgencia clínica**? La decide **Jev AI**
 * (nunca el texto por sí solo), con la pregunta `es_urgencia` (`noul`) del triaje de `php artisan jev:triaje`.
 *
 * Sirve a una única decisión: si el chatbot puede ofrecer horarios con **90 minutos** de anticipación
 * (`PacienteAtencionContextService::ANTICIPACION_URGENCIA_MINUTOS`) en vez de las 24 h de siempre.
 *
 * **Falla segura**: sólo devuelve `urgente = true` si Jev responde a tiempo, la respuesta es un número
 * válido y la probabilidad es >= el umbral de `services.jev.umbral` (0,70). Sin clave, con timeout,
 * error HTTP, respuesta ambigua o probabilidad baja, el resultado es `false` y rige la regla de 24 h.
 * Una sola llamada: sin reintentos y con timeout corto (`services.jev.urgencia_timeout`).
 *
 * El texto del paciente va a Jev como el `state` a clasificar, no como instrucciones: las instrucciones y
 * criterios de la pregunta son fijos. Y **no se escribe en ningún log** (puede traer datos clínicos): la
 * auditoría guarda la ficha o el teléfono enmascarado, el resultado, la probabilidad y el umbral.
 */
class UrgenciaClinicaJev
{
    /** Tope del texto enviado: un mensaje de WhatsApp normal cabe de sobra y acota el costo. */
    private const MAX_CARACTERES = 600;

    /**
     * La pregunta del triaje (misma que usa `jev:triaje`; se define una sola vez).
     *
     * @return array<string,mixed>
     */
    public static function pregunta(): array
    {
        return [
            'type' => 'noul',
            'instructions' => '¿Este mensaje describe una URGENCIA CLÍNICA: un cuadro que puede poner en riesgo la salud y que hay que escalar de inmediato a un médico? El apuro por conseguir una cita («urgente», «para hoy», «lo antes posible») NO es urgencia clínica si no hay síntomas.',
            'criteria' => [
                'true' => 'Síntomas agudos o potencialmente graves: dolor intenso, sangrado, fiebre alta, contracciones, desmayo, dificultad para respirar, riesgo para un bebé o un embarazo.',
                'false' => 'Pedido de cita (aunque sea «urgente»), consulta administrativa o duda clínica que puede esperar a una cita.',
            ],
        ];
    }

    /**
     * @return array{urgente:bool, probabilidad:?float, umbral:float, motivo:string}
     *         `motivo`: `urgencia`, `no_urgencia`, `bajo_umbral`, `sin_clave`, `sin_respuesta` o `error`.
     */
    public function evaluar(string $mensaje, string $referencia): array
    {
        $umbral = (float) config('services.jev.umbral', 0.7);
        $resultado = ['urgente' => false, 'probabilidad' => null, 'umbral' => $umbral, 'motivo' => 'error'];

        try {
            $jev = $this->servicio();

            if (! $jev->tieneClave()) {
                $resultado['motivo'] = 'sin_clave';
            } else {
                $texto = trim(mb_substr($mensaje, 0, self::MAX_CARACTERES));
                $respuesta = $jev->decidir($texto, ['es_urgencia' => self::pregunta()]);
                $valor = $respuesta['answers']['es_urgencia']['noul'] ?? null;

                if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor > 1) {
                    $resultado['motivo'] = 'sin_respuesta';
                } else {
                    $resultado['probabilidad'] = (float) $valor;
                    $resultado['urgente'] = (float) $valor >= $umbral;
                    $resultado['motivo'] = $resultado['urgente']
                        ? 'urgencia'
                        : ((float) $valor >= 0.5 ? 'bajo_umbral' : 'no_urgencia');
                }
            }
        } catch (Throwable $e) {
            // Sin el mensaje de la excepción en el log: puede traer el cuerpo de la respuesta del proveedor.
            $resultado['motivo'] = 'error';
            $resultado['error'] = class_basename($e);
        }

        $this->auditar($resultado, $referencia);

        return [
            'urgente' => $resultado['urgente'],
            'probabilidad' => $resultado['probabilidad'],
            'umbral' => $umbral,
            'motivo' => $resultado['motivo'],
        ];
    }

    /** `584165800403` → `***0403`. */
    public static function enmascarar(?string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono) ?? '';

        return $digitos === '' ? '(sin teléfono)' : '***' . substr($digitos, -4);
    }

    protected function servicio(): JevService
    {
        return new JevService(array_merge((array) config('services.jev', []), [
            'timeout' => (int) config('services.jev.urgencia_timeout', 4),
            'intentos' => 1,
        ]));
    }

    /** @param  array<string,mixed>  $resultado */
    private function auditar(array $resultado, string $referencia): void
    {
        $contexto = [
            'paciente' => $referencia,
            'urgente' => $resultado['urgente'],
            'motivo' => $resultado['motivo'],
            'probabilidad' => $resultado['probabilidad'],
            'umbral' => $resultado['umbral'],
        ];

        if (isset($resultado['error'])) {
            $contexto['error'] = $resultado['error'];
        }

        Log::info(
            $resultado['urgente']
                ? 'Chatbot: Jev afirma urgencia, la anticipación baja a 90 min'
                : 'Chatbot: Jev no afirma urgencia, rige la anticipación de 24 h',
            $contexto
        );
    }
}
