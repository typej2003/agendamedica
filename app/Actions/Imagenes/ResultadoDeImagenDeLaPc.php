<?php

namespace App\Actions\Imagenes;

/**
 * Qué pasó con una imagen de la PC (WEB-T.1): un **código estable** que la herramienta `subir-imagenes` usa para decidir
 * (reintentar, omitir, informar) y el estado HTTP con el que el controlador lo responde.
 *
 * Los que no son error (`ok = true`): `subida`, `reemplazada`, `ya_estaba`, `omitida`. Los demás son el motivo del rechazo y
 * **no cambian** entre versiones: la herramienta los compara por texto.
 */
final class ResultadoDeImagenDeLaPc
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $codigo,
        public readonly int $http,
        public readonly string $mensaje,
        public readonly array $datos = [],
    ) {
    }

    public static function exito(string $codigo, array $datos = [], string $mensaje = ''): self
    {
        return new self(true, $codigo, 200, $mensaje, $datos);
    }

    public static function error(string $codigo, int $http, string $mensaje, array $datos = []): self
    {
        return new self(false, $codigo, $http, $mensaje, $datos);
    }

    /** El cuerpo JSON de la respuesta. */
    public function aArreglo(): array
    {
        return $this->ok
            ? array_merge(['ok' => true, 'estado' => $this->codigo, 'mensaje' => $this->mensaje], $this->datos)
            : array_merge(['ok' => false, 'error' => $this->codigo, 'mensaje' => $this->mensaje], $this->datos);
    }
}
