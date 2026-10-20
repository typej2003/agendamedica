<?php

namespace App\Clinica\Guia;

/** Lo que le falta al médico para quedar listo, ya redactado: el texto del banner, el enlace y la pregunta del diálogo. */
final class PasoEsencial
{
    public function __construct(
        public readonly string $clave,
        public readonly string $ruta,
        public readonly array $parametros,
        public readonly string $banner,
        public readonly string $enlace,
        public readonly string $pregunta,
    ) {
    }

    public function url(): string
    {
        return route($this->ruta, $this->parametros);
    }
}
