<?php

namespace App\Actions\Consulta;

/**
 * Cómo terminó un intento de guardar la **evaluación física** de una consulta (WEB-4.3).
 *
 * Existe porque "no se guardó nada" tiene **dos causas distintas** y la pantalla tiene que decir cuál:
 *
 *  - **`sinCambios`**: el formulario venía igual a lo guardado. No es un problema, es que no había nada
 *    que hacer.
 *  - **`bloqueadas`** con algo adentro: el guard del sync (`RegistroDeCambios::permite`, el
 *    last-write-wins por columna) rechazó esas columnas porque hay una edición más nueva de otro equipo.
 *    Eso **sí** hay que decirlo —si no, el médico cree que guardó— y le dice qué columnas volver a cargar.
 *
 * Un solo `bool` no alcanzaba: los dos casos llegan como "cero columnas guardadas" y son mensajes
 * distintos (medido en los tests: el segundo guardado de los mismos valores avisaba de un conflicto que
 * no existía).
 */
final class ResultadoDeEvaluacion
{
    /**
     * @param  int  $guardadas  cuántas columnas quedaron escritas (las dos tablas juntas)
     * @param  array<int,string>  $bloqueadas  las columnas que el guard del sync no dejó pisar
     * @param  array<int,string>  $sinCambios  las columnas que ya tenían ese valor
     */
    public function __construct(
        public readonly int $guardadas = 0,
        public readonly array $bloqueadas = [],
        public readonly array $sinCambios = [],
    ) {
    }

    /** Hubo columnas que el sync no dejó pisar: la pantalla lo dice. */
    public function huboConflicto(): bool
    {
        return $this->bloqueadas !== [];
    }

    /** El formulario venía igual a lo guardado: no se escribió nada y no hay conflicto que contar. */
    public function sinCambios(): bool
    {
        return $this->guardadas === 0 && $this->bloqueadas === [];
    }
}
