<?php

namespace App\Actions\Pacientes;

use App\Models\Paciente;
use InvalidArgumentException;

/**
 * Cambiar la cédula de un paciente.
 *
 * Es la acción **"Cédula actual → modificada"** de `w_listado_pacientes_a_citas.srw:255-294`, la
 * ventana que la web reemplaza con "Citas del paciente" (WEB-2.15), y la misma que tiene
 * `w_listado_pacientes_a_modificar.srw:254-291`. La fuente hace un `UPDATE "pacientes" SET "nac", "cedula"
 * WHERE "numhistoria"` y **no valida nada**: no mira si el campo quedó vacío, no mira el largo del
 * `editmask` (`########`, 8 dígitos) y **no mira si la cédula nueva ya es de otro paciente**.
 *
 * Lo que se porta tal cual y lo que se decide distinto:
 *
 *  - **La cédula identifica a la persona en todo el sistema** (`CrearPaciente`, `PacientesLegado`): es
 *    la clave con la que el legado reconoce al mismo paciente entre consultorios y con la que el sync
 *    enlaza la ficha que baja del escritorio. Dejar que dos pacientes queden con la misma cédula
 *    significa que el próximo `pacientes` que suba el escritorio puede **fusionar dos personas**. Por
 *    eso, a diferencia de la fuente, una cédula que **ya es de otro paciente se rechaza**, con el
 *    nombre del otro para que el consultorio pueda darse cuenta. Es una decisión, no paridad, igual
 *    que la de `EliminarCita` (WEB-2.3).
 *  - **El prefijo `nac` no se toca.** La fuente lo escribe en el mismo `UPDATE`, pero el único camino
 *    del escritorio que la abre **deja el campo en blanco** (`:488`) y la web no tiene ese dato en
 *    ningún lado (el esquema lo dejó nullable y el sync del móvil no lo usa). Escribir `null` encima
 *    sería perder lo que hubiera.
 *  - La fila no cambia de `numhistoria` ni de dueño: es **el mismo paciente** con otra cédula. Nada
 *    más se toca.
 *
 * ⚠️ **Riesgo conocido, anotado y no arreglado**: `pacientes.cedula` es la identidad con la que
 * `App\Sync\Escritorio\PacientesLegado` enlaza la ficha del escritorio. La bajada (`/sync/cambios/bajar`,
 * Fase 2.B) **todavía no existe**, así que este cambio se queda en la nube y no llega a la PC del
 * consultorio (ver `PENDIENTES-POWERBUILDER.md`).
 */
final class CambiarCedula
{
    /** El largo del `editmask` de la fuente (`########`); la columna admite 10. */
    public const LARGO_MAXIMO = 10;

    /**
     * @param  string  $nuevaCedula  ya normalizada (sin espacios) por el llamador
     *
     * @throws InvalidArgumentException con el motivo, para mostrarlo en pantalla
     */
    public function ejecutar(Paciente $paciente, string $nuevaCedula): Paciente
    {
        $nuevaCedula = trim($nuevaCedula);

        if ($nuevaCedula === '') {
            throw new InvalidArgumentException('Escribe la cédula nueva: la cédula no puede quedar vacía.');
        }

        if (preg_match('/^[0-9]{1,' . self::LARGO_MAXIMO . '}$/', $nuevaCedula) !== 1) {
            throw new InvalidArgumentException('La cédula solo lleva números (hasta ' . self::LARGO_MAXIMO . ' dígitos).');
        }

        if (trim((string) $paciente->cedula) === $nuevaCedula) {
            throw new InvalidArgumentException('Esa ya es la cédula del paciente.');
        }

        // La cédula es del paciente, no del consultorio: la comparación es **global**, como la del
        // alta (`CrearPaciente`), porque el mismo `pacientes` lo comparten todos los médicos. La regla
        // —y el mensaje— viven en `IdentidadDePaciente`, que además usan el alta y la edición de la
        // ficha de WEB-3.2: no hay dos versiones de "esa cédula ya es de otro".
        $otro = IdentidadDePaciente::duenoDe($nuevaCedula, $paciente);

        if ($otro !== null) {
            throw new InvalidArgumentException(IdentidadDePaciente::cedulaOcupada($otro, $nuevaCedula));
        }

        $paciente->cedula = $nuevaCedula;
        $paciente->save();

        return $paciente;
    }
}
