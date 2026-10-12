<?php

namespace App\Clinica\Verificacion;

/**
 * **Las direcciones públicas de la verificación del récipe** (WEB-5.6): la de la página (`<url>/verificar`), la de un
 * récipe (`<url>/verificar/<token>`) y cómo se escribe la primera en la hoja (`doctorisimo.app/verificar`).
 *
 * La base sale de `config('clinica.verificacion.url')` (`VERIFICACION_URL`) o, si no está, de `APP_URL`: nunca de la
 * petición que imprime, porque el QR se escanea desde un teléfono de una farmacia y tiene que apuntar al sitio público.
 */
final class UrlDeVerificacion
{
    /** El token reservado de la hoja de prueba: la página lo reconoce y dice que es un ejemplo. */
    public const TOKEN_DE_EJEMPLO = 'ejemplo';

    public static function base(): string
    {
        $url = config('clinica.verificacion.url') ?: config('app.url');

        return rtrim((string) $url, '/');
    }

    public static function pagina(): string
    {
        return self::base() . '/verificar';
    }

    public static function deToken(string $token): string
    {
        return self::pagina() . '/' . $token;
    }

    /** `doctorisimo.app/verificar`: la dirección sin el esquema, para leerla en la hoja y teclearla. */
    public static function visible(): string
    {
        return preg_replace('#\Ahttps?://#i', '', self::pagina()) ?? self::pagina();
    }
}
