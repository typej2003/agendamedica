<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Las **credenciales de un método de pago**, cifradas con `APP_KEY`.
 *
 * En la base es un `TEXT` con el JSON cifrado; en el modelo, un `array`. Nunca se guarda el token en
 * claro ni se escribe en los logs. Si el `APP_KEY` cambió (o la fila se copió de otra instalación) el
 * descifrado falla y se devuelve `null`: el panel muestra el método como “sin configurar” en vez de
 * romper la pantalla.
 */
class CredencialesCifradasCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $json = Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return null;
        }

        $datos = json_decode($json, true);

        return is_array($datos) ? $datos : null;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
