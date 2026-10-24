<?php

namespace App\Clinica\Pacientes;

/**
 * El término con el que se busca, y lo que se escribió, cuando el corrector de errores de tecleo
 * (PecleSearch) cambió alguna palabra: "Gonzlez" se busca como "gonzalez".
 *
 * Es un valor inmutable y sin dependencias para que el controlador y el ViewModel no tengan que
 * repetir la comparación: `corregida()` es "lo aplicado no es lo escrito", y `sinCorregir()` vuelve a
 * lo escrito cuando la corrección no encontró nada o cuando el usuario pidió buscar tal cual.
 *
 * **La corrección nunca es la búsqueda.** El listado resuelve con `BusquedaDePacientes` (cada palabra
 * por cualquier parte, sin acentos, con cédula y n.º de historia) y este término sólo entra ahí: no se
 * reemplaza por el `whereIndexed` del paquete, que exige que cada palabra sea el **comienzo** de una
 * palabra del índice —cambiaría la búsqueda del listado— y que además mira el índice entero, sin el
 * filtro por consultorio. El índice es el **vocabulario** del corrector, nada más.
 */
final readonly class BusquedaCorregida
{
    /**
     * @param  string  $escrito  lo que escribió el usuario, tal cual (el valor del buscador)
     * @param  string  $aplicado  lo que se le pasa a la búsqueda del listado
     */
    public function __construct(
        public string $escrito,
        public string $aplicado,
    ) {
    }

    public static function sinCorreccion(string $texto): self
    {
        return new self($texto, $texto);
    }

    public function corregida(): bool
    {
        return $this->aplicado !== $this->escrito;
    }

    public function sinCorregir(): self
    {
        return self::sinCorreccion($this->escrito);
    }
}
