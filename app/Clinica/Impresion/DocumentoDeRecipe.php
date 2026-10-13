<?php

namespace App\Clinica\Impresion;

/**
 * **Un récipe listo para imprimir** (WEB-5.2): de quién es (el paciente o la pareja), de qué fecha y qué
 * medicamentos lleva. Lo arma `RecipesParaImprimir` y lo reparte en hojas `DistribuidorDeRecipe`.
 *
 * `persona` es lo que se imprime en los datos de arriba: `nombre`, `cedula` (ya con su nacionalidad, o `null`),
 * `edad` (en años a la fecha del récipe, o `null`) y `direccion` (o `null`). En el **récipe de la pareja** no
 * hay edad ni dirección: el legado les imprime los datos de la pareja que se cargaron (cédula y nombre) y los
 * demás no se conocen.
 */
final class DocumentoDeRecipe
{
    public const PACIENTE = 'paciente';

    public const PAREJA = 'pareja';

    /**
     * @param  array{nombre:string, cedula:?string, edad:?int, direccion:?string}  $persona
     * @param  array<int,ItemImpreso>  $items
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $titulo,
        public readonly ?int $numero,
        public readonly string $fecha,
        public readonly array $persona,
        public readonly array $items,
        public readonly ?QrDelRecipe $qr = null,
    ) {
    }

    /** El mismo récipe con su QR de verificación (WEB-5.6): lo agrega quien imprime, después de armarlo. */
    public function conQr(QrDelRecipe $qr): self
    {
        return new self($this->tipo, $this->titulo, $this->numero, $this->fecha, $this->persona, $this->items, $qr);
    }

    public function esDeLaPareja(): bool
    {
        return $this->tipo === self::PAREJA;
    }

    public function estaVacio(): bool
    {
        return $this->items === [];
    }
}
