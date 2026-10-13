<?php

namespace App\Notificaciones;

/**
 * **Lo que el editor le dice al médico de la plantilla que está escribiendo** (WEB-8.4): cómo queda el mensaje con datos de
 * ejemplo, cuánto mide (caracteres y SMS) y qué hay que revisar (etiquetas que no existen, falta de quién es el paciente…).
 *
 * El mensaje de ejemplo lo arma **la misma clase que arma el real** (`PlantillaDeMensaje::armar` →
 * `MensajeDeRecordatorio`/`MensajeDeCumpleanos`), de modo que lo que se ve es lo que sale. El resultado es un arreglo simple que
 * sirve igual para pintar la pantalla al cargar y para responder al JSON de la vista previa en vivo: la lógica de las etiquetas
 * vive **solo acá** y el JS no la repite.
 *
 * Sin base de datos ni HTTP.
 */
final class AnalisisDeUnaPlantilla
{
    /**
     * @param  array<string,?string>  $datosDeEjemplo  `paciente`, `doctor` y, en la de citas, `fecha`, `hora` y `centro`
     * @return array{
     *     vacia:bool, vista_previa:string, caracteres:int, maximo:int, excede:bool,
     *     sms:array{codificacion:string, unidades:int, segmentos:int, limite:int, limite_uno:int},
     *     avisos:array<int,array{tono:string, texto:string}>
     * }
     */
    public static function de(PlantillaDeMensaje $plantilla, ?string $texto, array $datosDeEjemplo): array
    {
        $texto = (string) $texto;
        $vacia = trim($texto) === '';
        $vistaPrevia = $plantilla->armar($texto, $datosDeEjemplo);
        $caracteres = mb_strlen($texto);

        return [
            'vacia' => $vacia,
            'vista_previa' => $vistaPrevia,
            'caracteres' => $caracteres,
            'maximo' => $plantilla->maximo(),
            'excede' => $caracteres > $plantilla->maximo(),
            'sms' => LargoDeSms::de($vistaPrevia),
            'avisos' => self::avisos($plantilla, $texto, $vacia, $caracteres),
        ];
    }

    /** @return array<int,array{tono:string, texto:string}> */
    private static function avisos(PlantillaDeMensaje $plantilla, string $texto, bool $vacia, int $caracteres): array
    {
        if ($vacia) {
            return [['tono' => 'info', 'texto' => 'Sin texto: se usa el mensaje por defecto («' . $plantilla->porDefecto . '»).']];
        }

        $avisos = [];

        foreach ($plantilla->etiquetasDesconocidas($texto) as $etiqueta) {
            $sugerida = $plantilla->sugerencia($etiqueta);

            $avisos[] = ['tono' => 'aviso', 'texto' => $sugerida !== null
                ? "{$etiqueta} no se reemplaza porque las etiquetas van en minúscula: escribe {$sugerida}."
                : "{$etiqueta} no es una etiqueta de esta plantilla: el paciente la leería tal cual. Las que se reemplazan son "
                    . implode(', ', $plantilla->etiquetasValidas()) . '.'];
        }

        if (! $plantilla->usa($texto, '{paciente}')) {
            $avisos[] = ['tono' => 'aviso', 'texto' => 'Falta {paciente}: el mensaje no dice a quién va dirigido.'];
        }

        if ($plantilla->slug === PlantillaDeMensaje::CITA && (! $plantilla->usa($texto, '{fecha}') || ! $plantilla->usa($texto, '{hora}'))) {
            $falta = array_values(array_filter(['{fecha}', '{hora}'], fn (string $etiqueta) => ! $plantilla->usa($texto, $etiqueta)));
            $avisos[] = ['tono' => 'info', 'texto' => 'Un recordatorio suele decir cuándo es la cita: no usa ' . implode(' ni ', $falta) . '.'];
        }

        if ($caracteres > $plantilla->maximo()) {
            $avisos[] = ['tono' => 'aviso', 'texto' => "El texto pasa de los {$plantilla->maximo()} caracteres que admite: no se podrá guardar."];
        }

        return $avisos;
    }
}
