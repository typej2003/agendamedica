<?php

namespace App\Actions\Configuracion;

use App\Models\Medico;
use App\Notificaciones\PlantillasDeMensaje;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * **Guardar las plantillas de mensaje del médico** (WEB-8.4): la de **citas** (`evolucion.plantilla_cita`, el recordatorio) y la
 * de **cumpleaños** (`evolucion.plantilla_cumple`, el saludo).
 *
 * ## Una sola regla de escritura con el API del móvil (R3)
 *
 * Las plantillas no tienen una escritura propia: son dos columnas de la configuración del médico, y **esa** escritura es
 * `GuardarMembreteDelMedico`, la que ya usa el API del móvil (`POST /app/configuracion`, que además guarda los textos del
 * membrete en la misma llamada). Esta Action es la entrada de la **web**: se asegura de que sólo se toquen estas dos columnas
 * y delega en la misma regla, así que no hay una segunda manera de escribirlas. Lo que comparten, entonces:
 *
 *  - **El largo máximo** (`LimitesDelMembrete`, 500: el del esquema), que valida el controlador del API y también el de la web.
 *  - **Sólo lo que cambió**: `''` y `null` son el mismo estado (sin plantilla) y no cuentan como cambio.
 *  - **Dónde se guarda**: bajo el **registro principal** del médico, una fila por médico (`evolucion`), la misma que lee el móvil.
 *  - **El rastro**: con `$origen = 'web'` cada columna escrita deja su `updated` en `sync_changes` y se respeta el
 *    last-write-wins por columna (si el móvil o el escritorio la editaron después, no se pisa y se informa en `bloqueadas`);
 *    con `$origen = null` —el API del móvil, que nunca dejó rastro de esta tabla— no hay rastro, igual que antes.
 *
 * **Plantilla vacía = usa la de por defecto.** Quitar el texto (o «Restablecer») guarda `NULL`: el envío del recordatorio
 * (`MensajeDeRecordatorio`) y el del saludo (`MensajeDeCumpleanos`) caen entonces en su mensaje por defecto, y el móvil ya hace lo
 * mismo (`texto.isEmpty ? porDefecto`).
 *
 * Los saltos de línea se guardan como `\n`: el navegador los manda como `\r\n` y el largo que cuenta el `maxlength` del campo
 * y el de la validación tiene que ser el mismo.
 */
final class GuardarPlantillasDeMensaje
{
    public function __construct(private GuardarMembreteDelMedico $membrete)
    {
    }

    /**
     * @param  array<string,?string>  $plantillas  `columna => texto` (`plantilla_cita` y/o `plantilla_cumple`); `null` o vacío la deja sin texto
     *
     * @throws InvalidArgumentException  si viene otra columna, un texto pasa del largo o el médico no tiene registro principal
     */
    public function ejecutar(
        Medico $medico,
        array $plantillas,
        ?CarbonInterface $cuando = null,
        ?string $origen = null,
    ): ResultadoDelMembrete {
        $permitidas = array_map(fn ($plantilla) => $plantilla->columna, PlantillasDeMensaje::todas());
        $campos = [];

        foreach ($plantillas as $columna => $texto) {
            if (! in_array($columna, $permitidas, true)) {
                throw new InvalidArgumentException("«{$columna}» no es una plantilla de mensaje.");
            }

            $texto = $texto === null ? null : trim(str_replace("\r\n", "\n", (string) $texto));
            $maximo = (int) LimitesDelMembrete::de($columna);

            if ($texto !== null && mb_strlen($texto) > $maximo) {
                throw new InvalidArgumentException("La plantilla no puede tener más de {$maximo} caracteres.");
            }

            $campos[$columna] = $texto;
        }

        return $this->membrete->ejecutar($medico, $campos, null, false, $cuando, $origen);
    }
}
