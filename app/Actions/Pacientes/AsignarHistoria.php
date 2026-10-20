<?php

namespace App\Actions\Pacientes;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Llenar la historia** de un paciente que ya está dado de alta sin número: le asigna el
 * `numhistoria` de su consultorio y deja la ficha lista para atender.
 *
 * Es la regla **"el número lo asigna el servidor, con el correlativo del consultorio"** (wiki
 * `04-historia-clinica.md`: "No se atiende sin historia") y vive **acá y en un solo lugar**, como las
 * Actions de agenda (PLAN-WEB.md, R3). La usan las superficies:
 *
 *  - el **sync del móvil**, cuando un teléfono manda la creación de `historias` al atender
 *    (`App\Sync\CreacionesClinicas`), y
 *  - la **web**, con la acción "Pasar a cita formal" del panel "Pacientes sin historia" de la agenda
 *    (WEB-2.11) y con el alta de la ficha, que numera en el mismo paso (O-04). El rótulo del escritorio
 *    está sobre un botón muerto (`w_hacer_cita.srw:801-805`); la acción real es la de
 *    `w_pasar_pacientes_n_reg_consulta_cita.srw`, que asigna un número nuevo (`:161-176`, la última del
 *    `DataWindow` + 1) y recién después enlaza la cita — eso es lo que se porta, sobre el modelo de
 *    AppDDR en vez de `paciente_no_regi` (decisión del 2026-10-10).
 *
 * Seis cosas que no son obvias y **son** la regla:
 *
 *  - **El vínculo se busca por paciente + consultorio**, no por médico. Desde la Fase 1 del cambio de
 *    `reg_medico` el vínculo (`medico_pacientes`) es del **tenant**: una sola fila por
 *    `(reg_medico, paciente_id)`, aunque el `medico_id` sea el de otro doctor o el del centro. Preguntar
 *    por `medico_id` dejaría afuera al paciente que vinculó otro doctor del mismo consultorio.
 *  - **El número es el correlativo del consultorio**, no el de todos los registros del médico: la última
 *    de `historias` de ese `reg_medico` **más** las del pivote del mismo `reg_medico`, comparando como
 *    entero (la columna guarda texto), y + 1. La unicidad del esquema es `(reg_medico, numhistoria)`, así
 *    que dos caminos pueden calcular el mismo número: el que pierde **reintenta** con el siguiente en
 *    vez de romper. Por eso un paciente del mismo doctor en otro consultorio **no** corre este
 *    correlativo.
 *  - **Es idempotente**: si el paciente ya tiene historia **en este consultorio**, devuelve la que tiene
 *    y no numera otra vez. Con eso el reintento del sync y el doble clic de la web terminan en el mismo
 *    lugar.
 *  - **La cita sigue siendo la misma fila**: las de `cola` que estaban ancladas al paciente por
 *    `paciente_sinhistoria_id` y sin número **de este consultorio** pasan a llevarlo. La agenda y el sync
 *    leen `cola.numhistoria`, así que esa columna es lo que hace que la cita pase a ser "formal" sin
 *    duplicarla ni reinsertarla.
 *  - **El `reg_medico` sale del vínculo del paciente**, nunca de un formulario: es el tenant, y una
 *    superficie no lo elige (por eso llega como parámetro y no lo busca el formulario).
 *  - **El número puede estar ya en el vínculo sin la fila de `historias`** (una pantalla o un script que
 *    escribió sólo el pivote: trampa 143). Ese número es el que la ficha, la agenda y las citas muestran,
 *    así que se **registra con él** en `historias` en vez de numerar otro: numerar otro dejaría las citas
 *    de ese número apuntando a una historia que no existe y al paciente mostrando un número que no se
 *    puede atender. Sólo se respeta si el número está libre o ya es de ese mismo paciente; si es de otro,
 *    no se le quita la historia a nadie y se numera como siempre.
 */
final class AsignarHistoria
{
    /** Intentos totales antes de dejar subir el error de la base (misma cuenta que el sync tenía). */
    private const INTENTOS = 3;

    /**
     * @param  Medico  $medico  el médico **del contexto**: es el que queda en `historias.medico_id`
     * @param  string  $regMedico  el consultorio (tenant) del vínculo: de él sale el correlativo y es el
     *                            `reg_medico` de la historia nueva
     * @param  int  $pacienteId  `pacientes.id` de la cita que se quiere pasar a formal — el que la
     *                           cita ancla en `cola.paciente_sinhistoria_id`
     *
     * @throws InvalidArgumentException si ese paciente no está vinculado a este consultorio
     */
    public function ejecutar(Medico $medico, string $regMedico, int $pacienteId): Historia
    {
        // Sin el vínculo no se numera: el número es **de este consultorio**, y un paciente de otro no se
        // toca (la web responde 404 con esto mismo).
        $relacion = MedicoPaciente::where('reg_medico', $regMedico)
            ->where('paciente_id', $pacienteId)
            ->first();

        if (! $relacion) {
            throw new InvalidArgumentException('Ese paciente no es de este médico.');
        }

        $yaTiene = $this->historiaDe($regMedico, (int) $relacion->paciente_id);
        if ($yaTiene !== null) {
            return $yaTiene;
        }

        // Dos teléfonos (o el escritorio) llenando historias a la vez pueden calcular el mismo número:
        // la unicidad `(reg_medico, numhistoria)` hace que uno falle, y ese reintenta con el siguiente.
        for ($intento = 0; ; $intento++) {
            try {
                return DB::transaction(function () use ($relacion, $regMedico, $medico) {
                    $historia = $this->laQueElVinculoYaTiene($relacion, $regMedico);

                    if ($historia === null) {
                        // Número nuevo: el correlativo del consultorio.
                        $historia = new Historia([
                            'numhistoria' => (string) $this->siguienteNumero($regMedico),
                            'reg_medico'  => $regMedico,
                            'paciente_id' => $relacion->paciente_id,
                        ]);
                    }

                    // La fila —nueva o adoptada— queda del médico del contexto.
                    $historia->medico_id = $medico->id;
                    $historia->save();

                    $numero = (int) $historia->numhistoria;

                    // El vínculo lleva el número **de la historia**, no uno calculado aparte: los dos
                    // textos quedan iguales aunque el legado los haya escrito con ceros a la izquierda.
                    $relacion->numhistoria = (string) $historia->numhistoria;
                    $relacion->save();

                    // La cita "sin historia" **del mismo consultorio** se enlaza a la historia nueva sin
                    // cambiar de fila: es la misma cita, y es lo que el escritorio y la agenda leen para
                    // saber que ya es formal. La de otro consultorio es otra historia y no se toca.
                    Cola::where('paciente_sinhistoria_id', $relacion->paciente_id)
                        ->where('reg_medico', $regMedico)
                        ->whereNull('numhistoria')
                        ->update(['numhistoria' => $numero]);

                    return $historia;
                });
            } catch (QueryException $e) {
                if ($intento >= self::INTENTOS - 1) {
                    throw $e;
                }
            }
        }
    }

    /**
     * La historia que el vínculo **ya dice tener**, o `null` si hay que numerar una nueva.
     *
     * Es el caso de la trampa 143: el número quedó escrito en `medico_pacientes` sin su fila (una pantalla
     * o un script que escribió sólo el pivote). Respetarlo es lo único que deja coherentes la ficha, la
     * agenda y las citas, que ya muestran ese número.
     *
     * Devuelve **sin guardar** la fila a crear cuando el número está libre; la fila existente cuando ya es
     * **del mismo paciente** (una vieja a la que le falta el `medico_id`); y `null` cuando el número es de
     * **otro** paciente —ahí no se le quita la historia a nadie y se numera como siempre—.
     *
     * Se busca por el texto del vínculo **y** por su forma numérica, porque el legado escribe el mismo
     * número de las dos maneras (`01001` en el pivote, `1001` en la historia).
     */
    private function laQueElVinculoYaTiene(MedicoPaciente $relacion, string $regMedico): ?Historia
    {
        $numero = CamposDePaciente::historiaNumerica($relacion->numhistoria);

        if ($numero === null) {
            return null;
        }

        $duena = Historia::where('reg_medico', $regMedico)
            ->whereIn('numhistoria', array_values(array_unique([
                (string) CamposDePaciente::numeroDeHistoria($relacion->numhistoria),
                (string) $numero,
            ])))
            ->first();

        if ($duena === null) {
            return new Historia([
                'numhistoria' => (string) $numero,
                'reg_medico'  => $regMedico,
                'paciente_id' => $relacion->paciente_id,
            ]);
        }

        return (int) $duena->paciente_id === (int) $relacion->paciente_id ? $duena : null;
    }

    /**
     * El correlativo vigente del **consultorio**: la última de `historias` de ese `reg_medico` **más** las
     * del pivote del mismo `reg_medico`, + 1.
     *
     * Las dos vías son necesarias porque el legado deja la historia en las dos: `historias` es la que
     * llena el sync (y el escritorio, que sube pacientes) y `medico_pacientes.numhistoria` es el número
     * que muestra la ficha. El número se guarda como texto, así que se compara como entero en PHP —
     * `max()` sobre texto ordenaría "9" después de "10".
     *
     * No mira los demás registros del médico: el paciente de otro consultorio es **otra historia**, con
     * su propio correlativo (Fase 1 del cambio de `reg_medico`).
     */
    public function siguienteNumero(string $regMedico): int
    {
        $numeros = Historia::where('reg_medico', $regMedico)->pluck('numhistoria')
            ->merge(MedicoPaciente::where('reg_medico', $regMedico)->pluck('numhistoria'))
            ->filter(fn ($n) => $n !== null && is_numeric($n))
            ->map(fn ($n) => (int) $n);

        return ((int) $numeros->max()) + 1;
    }

    /**
     * La historia que el paciente **ya tiene en este consultorio**, o `null` si todavía no tiene ninguna.
     *
     * Se busca por el tenant y el paciente, no por `medico_id`: la historia es del consultorio y el
     * `medico_id` es sólo el doctor que la registró (puede haberlo hecho otro doctor del mismo centro).
     */
    private function historiaDe(string $regMedico, int $pacienteId): ?Historia
    {
        return Historia::where('reg_medico', $regMedico)
            ->where('paciente_id', $pacienteId)
            ->orderBy('id')
            ->first();
    }
}
