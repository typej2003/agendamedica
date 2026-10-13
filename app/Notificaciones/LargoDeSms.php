<?php

namespace App\Notificaciones;

/**
 * **Cuántos SMS ocupa un texto** (WEB-8.4): la cuenta que el editor de plantillas le muestra al médico para que sepa cuándo un
 * mensaje se parte en varios.
 *
 * Un SMS no mide "160 caracteres" siempre:
 *
 *  - Con el alfabeto **GSM-7** (letras sin tilde, números, puntuación común y unas pocas letras como `é`, `ñ`, `ü`) cabe **160**
 *    en un solo mensaje; los símbolos `{ } [ ] \ ^ ~ | €` ocupan **dos** lugares.
 *  - Basta **un** carácter fuera de ese alfabeto (una `á`, una `í`, una `ó`, una `ú`, un emoji) para que todo el mensaje pase a
 *    **Unicode (UCS-2)** y quepan sólo **70** (un emoji ocupa dos lugares).
 *  - Cuando no cabe en uno, el operador lo parte en trozos **más cortos** porque cada trozo lleva su cabecera de concatenación:
 *    **153** (GSM-7) o **67** (Unicode) por mensaje.
 *
 * El envío por **Twilio** (`SmsService`) parte el texto solo y cobra por trozo; WhatsApp y el correo no tienen este límite. La
 * clase es una función pura (sin base de datos ni HTTP): la usan el ViewModel del editor y su vista previa.
 */
final class LargoDeSms
{
    public const GSM_UNO = 160;

    public const GSM_VARIOS = 153;

    public const UNICODE_UNO = 70;

    public const UNICODE_VARIOS = 67;

    /** El alfabeto básico GSM 03.38 (cada carácter ocupa un lugar). */
    private const BASICO = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Los caracteres de la tabla de extensión (cada uno ocupa dos lugares). */
    private const EXTENSION = "^{}\\[~]|€\f";

    /**
     * @return array{codificacion:string, unidades:int, segmentos:int, limite:int, limite_uno:int}
     *         `codificacion`: `gsm7` o `unicode`; `unidades`: los lugares que ocupa el texto; `segmentos`: cuántos SMS son;
     *         `limite`: cuántos lugares caben en cada uno (153/67 si son varios); `limite_uno`: cuántos caben si es uno solo.
     */
    public static function de(string $texto): array
    {
        $unidades = self::unidadesGsm($texto);

        if ($unidades !== null) {
            return self::resultado('gsm7', $unidades, self::GSM_UNO, self::GSM_VARIOS);
        }

        // UCS-2 cuenta unidades UTF-16: un emoji (fuera del plano básico) ocupa dos.
        $unidades = (int) (strlen((string) mb_convert_encoding($texto, 'UTF-16BE', 'UTF-8')) / 2);

        return self::resultado('unicode', $unidades, self::UNICODE_UNO, self::UNICODE_VARIOS);
    }

    /** ¿El texto entra en el alfabeto GSM-7? Si sí, cuántos lugares ocupa; si no, `null`. */
    private static function unidadesGsm(string $texto): ?int
    {
        $unidades = 0;

        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letra) {
            if (mb_strpos(self::BASICO, $letra) !== false) {
                $unidades += 1;
            } elseif (mb_strpos(self::EXTENSION, $letra) !== false) {
                $unidades += 2;
            } else {
                return null;
            }
        }

        return $unidades;
    }

    /** @return array{codificacion:string, unidades:int, segmentos:int, limite:int, limite_uno:int} */
    private static function resultado(string $codificacion, int $unidades, int $uno, int $varios): array
    {
        $segmentos = $unidades === 0 ? 0 : ($unidades <= $uno ? 1 : (int) ceil($unidades / $varios));

        return [
            'codificacion' => $codificacion,
            'unidades' => $unidades,
            'segmentos' => $segmentos,
            'limite' => $segmentos > 1 ? $varios : $uno,
            'limite_uno' => $uno,
        ];
    }
}
