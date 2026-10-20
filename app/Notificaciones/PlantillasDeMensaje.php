<?php

namespace App\Notificaciones;

use App\Cumpleanos\MensajeDeCumpleanos;

/**
 * **Las dos plantillas de mensaje del médico** (WEB-8.4): la fuente única de cuáles hay, con qué etiquetas y con qué texto por
 * defecto. Las etiquetas son **las que el servidor realmente sustituye** (`MensajeDeRecordatorio::ETIQUETAS` y
 * `MensajeDeCumpleanos::ETIQUETAS`, que son también las del editor del móvil): una etiqueta que el servidor no conoce no se
 * ofrece, porque llegaría tal cual al paciente.
 *
 * La plantilla de **cumpleaños** sólo tiene `{paciente}` y `{doctor}` a propósito (ver `MensajeDeCumpleanos`).
 */
final class PlantillasDeMensaje
{
    /** @return array<int,PlantillaDeMensaje> en el orden de la pantalla */
    public static function todas(): array
    {
        return [self::de(PlantillaDeMensaje::CITA), self::de(PlantillaDeMensaje::CUMPLE)];
    }

    public static function de(string $slug): PlantillaDeMensaje
    {
        return match ($slug) {
            PlantillaDeMensaje::CITA => new PlantillaDeMensaje(
                PlantillaDeMensaje::CITA,
                'plantilla_cita',
                'Recordatorio de cita',
                'Es el mensaje con que se avisa de una cita por SMS, WhatsApp o correo, desde la agenda y desde el envío del día.',
                MensajeDeRecordatorio::PLANTILLA_POR_DEFECTO,
                [
                    '{paciente}' => ['texto' => 'Paciente', 'ayuda' => 'El nombre del paciente.'],
                    '{fecha}' => ['texto' => 'Fecha', 'ayuda' => 'El día de la cita (dd/mm/aaaa).'],
                    '{hora}' => ['texto' => 'Hora', 'ayuda' => 'La hora de la cita, con AM/PM (8:00 AM).'],
                    '{doctor}' => ['texto' => 'Médico', 'ayuda' => 'El nombre del médico de la cita.'],
                    '{centro}' => ['texto' => 'Consultorio', 'ayuda' => 'El consultorio o centro donde es la cita.'],
                ],
                MensajeDeRecordatorio::class,
            ),
            PlantillaDeMensaje::CUMPLE => new PlantillaDeMensaje(
                PlantillaDeMensaje::CUMPLE,
                'plantilla_cumple',
                'Saludo de cumpleaños',
                'Es el saludo que sale el día del cumpleaños del paciente, solo o a mano desde Cumpleaños.',
                MensajeDeCumpleanos::PLANTILLA_POR_DEFECTO,
                [
                    '{paciente}' => ['texto' => 'Paciente', 'ayuda' => 'El nombre del paciente.'],
                    '{doctor}' => ['texto' => 'Médico', 'ayuda' => 'El nombre del médico.'],
                ],
                MensajeDeCumpleanos::class,
            ),
            default => throw new \InvalidArgumentException("Plantilla de mensaje desconocida: {$slug}."),
        };
    }

    public static function existe(string $slug): bool
    {
        return in_array($slug, [PlantillaDeMensaje::CITA, PlantillaDeMensaje::CUMPLE], true);
    }

    /** @return array<int,string> */
    public static function slugs(): array
    {
        return [PlantillaDeMensaje::CITA, PlantillaDeMensaje::CUMPLE];
    }
}
