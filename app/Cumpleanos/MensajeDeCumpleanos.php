<?php

namespace App\Cumpleanos;

/**
 * El texto del saludo de cumpleaños, con las etiquetas ya sustituidas.
 *
 * **Las mismas etiquetas que el editor del móvil** (`TipoPlantilla.cumple` de
 * `plantilla_mensaje_screen.dart`): `{paciente}` y `{doctor}`. No se agregan `{fecha}`, `{hora}` ni
 * `{centro}` a propósito: el editor del móvil no las ofrece para esta plantilla y el médico que las
 * escribiera a mano se las encontraría sin sustituir. Es el mismo criterio de reemplazo que el
 * recordatorio (`MensajeDeRecordatorio`) para que las dos plantillas se comporten igual.
 *
 * La plantilla por defecto es la misma del móvil (`TipoPlantilla.cumple.porDefecto`): un médico que
 * nunca abrió el editor manda el saludo que el editor le habría mostrado.
 */
final class MensajeDeCumpleanos
{
    public const PLANTILLA_POR_DEFECTO = '¡Feliz cumpleaños {paciente}! Le deseamos un excelente día. Saludos.';

    /** Las etiquetas que la plantilla entiende (las del editor del móvil). */
    public const ETIQUETAS = ['{paciente}', '{doctor}'];

    /**
     * @param  array{paciente?:?string,doctor?:?string}  $datos
     */
    public function armar(?string $plantilla, array $datos): string
    {
        $texto = trim((string) $plantilla) === '' ? self::PLANTILLA_POR_DEFECTO : (string) $plantilla;

        return trim(strtr($texto, [
            '{paciente}' => (string) ($datos['paciente'] ?? 'paciente'),
            '{doctor}'   => (string) ($datos['doctor'] ?? ''),
        ]));
    }

    /**
     * La vista previa que la pantalla muestra arriba del formulario de configuración: el saludo del
     * **primer** paciente de hoy (o uno de ejemplo si hoy no cumple nadie), con el nombre del médico.
     */
    public function ejemplo(?string $plantilla, ?Cumpleano $cumpleano, string $doctor): string
    {
        return $this->armar($plantilla, [
            'paciente' => $cumpleano?->nombreParaElSaludo() ?? 'Ana Pérez',
            'doctor'   => $doctor,
        ]);
    }
}
