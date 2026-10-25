<?php

namespace App\Clinica\Escaneo;

use RuntimeException;

/**
 * La hoja no se pudo leer. El `motivo` decide la respuesta HTTP y el mensaje (en tuteo) es lo que ve el médico:
 *
 *  - `no_disponible`: falta la clave del proveedor (503).
 *  - `no_es_resultado`: la imagen no es una hoja de resultados o no se lee (422).
 *  - `proveedor`: el servicio falló, tardó o se negó (502).
 */
final class LecturaFallida extends RuntimeException
{
    public const NO_DISPONIBLE = 'no_disponible';

    public const NO_ES_RESULTADO = 'no_es_resultado';

    public const PROVEEDOR = 'proveedor';

    private function __construct(public readonly string $motivo, string $mensaje, ?\Throwable $causa = null)
    {
        parent::__construct($mensaje, 0, $causa);
    }

    public static function noDisponible(): self
    {
        return new self(self::NO_DISPONIBLE, 'La lectura automática no está configurada en este servidor.');
    }

    public static function noEsResultado(): self
    {
        return new self(self::NO_ES_RESULTADO,
            'No se reconoció una hoja de resultados en el archivo. Prueba con una foto más nítida, de frente y con buena luz.');
    }

    public static function delProveedor(?\Throwable $causa = null): self
    {
        return new self(self::PROVEEDOR,
            'No se pudo leer la hoja en este momento. Inténtalo de nuevo en unos minutos o escribe el resultado a mano.', $causa);
    }

    public function estadoHttp(): int
    {
        return match ($this->motivo) {
            self::NO_DISPONIBLE => 503,
            self::NO_ES_RESULTADO => 422,
            default => 502,
        };
    }
}
