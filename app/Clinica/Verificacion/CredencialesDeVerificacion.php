<?php

namespace App\Clinica\Verificacion;

/**
 * **Las dos credenciales públicas de un récipe** (WEB-5.6): el `token` que lleva el QR y el `codigo` corto que se
 * tipea si el QR no se lee. Sin estado y sin base de datos.
 *
 * ## El token
 *
 * 32 bytes de `random_bytes` (CSPRNG) en base64url sin relleno = **43 caracteres** y 256 bits: no se adivina ni se
 * enumera. Es una *capacidad*: quien lo tiene ve la página. Por eso no se loguea, no viaja en `sync_changes` y la
 * página responde lo mismo ante uno mal formado que ante uno que no existe.
 *
 * ## El código
 *
 * 8 caracteres de un alfabeto de 31 símbolos **sin 0, O, 1, I ni L** (los que se confunden al leer una hoja
 * impresa), mostrados `ABCD-EFGH`. Es más corto y por eso más adivinable que el token; lo protege un límite de
 * peticiones más estricto y que lo único que revela la página es lo público.
 */
final class CredencialesDeVerificacion
{
    public const LARGO_DEL_TOKEN = 43;

    public const LARGO_DEL_CODIGO = 8;

    private const ALFABETO_DEL_CODIGO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function nuevoToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** ¿Tiene la forma de un token? Una forma distinta ni se busca en la base. */
    public static function tieneFormaDeToken(string $token): bool
    {
        return strlen($token) === self::LARGO_DEL_TOKEN && preg_match('/\A[A-Za-z0-9_-]+\z/', $token) === 1;
    }

    /** Un código nuevo, sin guion (`ABCDEFGH`). */
    public static function nuevoCodigo(): string
    {
        $alfabeto = self::ALFABETO_DEL_CODIGO;
        $ultimo = strlen($alfabeto) - 1;
        $codigo = '';

        for ($i = 0; $i < self::LARGO_DEL_CODIGO; $i++) {
            $codigo .= $alfabeto[random_int(0, $ultimo)];
        }

        return $codigo;
    }

    /** Lo que se tipeó, normalizado para buscarlo: mayúsculas y sin espacios ni guiones. */
    public static function normalizarCodigo(string $texto): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $texto) ?? '');
    }

    /** Un código normalizado, ¿tiene la forma de uno (largo y alfabeto)? */
    public static function tieneFormaDeCodigo(string $codigo): bool
    {
        return strlen($codigo) === self::LARGO_DEL_CODIGO
            && preg_match('/\A[' . self::ALFABETO_DEL_CODIGO . ']+\z/', $codigo) === 1;
    }

    /** `ABCDEFGH` → `ABCD-EFGH`: como se imprime. */
    public static function formatearCodigo(string $codigo): string
    {
        return substr($codigo, 0, 4) . '-' . substr($codigo, 4);
    }
}
