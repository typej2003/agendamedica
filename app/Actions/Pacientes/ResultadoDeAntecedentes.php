<?php

namespace App\Actions\Pacientes;

/**
 * Cómo terminó un intento de guardar los **antecedentes de un paciente** (WEB-4.4).
 *
 * Existe por la misma razón que `ResultadoDeEvaluacion`: "no se guardó nada" tiene **varias causas
 * distintas** y la pantalla tiene que decir cuál en vez de mentir con un "Guardado":
 *
 *  - **`sinCambios`** con todo adentro: el formulario venía igual a lo guardado. No es un problema.
 *  - **`bloqueados`**: el guard del sync (`RegistroDeCambios::permite`, el last-write-wins por columna)
 *    rechazó esa fila porque otro equipo la editó más recientemente, o el código que se quiso agregar no
 *    está en el catálogo del médico. Las dos cosas se nombran y **no se pisan**.
 *  - **`agregados` / `quitados`**: las altas y bajas que sí pasaron. Se cuentan aparte de las ediciones
 *    porque son lo que el médico ve cambiar en la lista.
 *
 * Las listas traen **nombres** y no columnas: acá la unidad es el antecedente (una fila con su nombre),
 * no cada columna, que es como el médico lee la sección.
 */
final class ResultadoDeAntecedentes
{
    /**
     * @param  array<int,string>  $guardados  los antecedentes cuya fila se escribió (detalle o alerta)
     * @param  array<int,string>  $agregados  los que se agregaron del catálogo
     * @param  array<int,string>  $quitados  los que se quitaron del paciente
     * @param  array<int,string>  $bloqueados  los que el sync no dejó tocar (o el código que no está en el catálogo)
     * @param  array<int,string>  $sinCambios  los que ya estaban como el formulario los mandó
     */
    public function __construct(
        public readonly array $guardados = [],
        public readonly array $agregados = [],
        public readonly array $quitados = [],
        public readonly array $bloqueados = [],
        public readonly array $sinCambios = [],
    ) {
    }

    /** Cuántos antecedentes quedaron escritos: filas editadas, agregadas y quitadas. */
    public function escritos(): int
    {
        return count($this->guardados) + count($this->agregados) + count($this->quitados);
    }

    /** Hubo algo que el sync no dejó tocar: la pantalla lo dice. */
    public function huboConflicto(): bool
    {
        return $this->bloqueados !== [];
    }

    /** El formulario venía igual a lo guardado: no se escribió nada y no hay conflicto que contar. */
    public function sinCambios(): bool
    {
        return $this->escritos() === 0 && $this->bloqueados === [];
    }
}
