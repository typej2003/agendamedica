<?php

namespace App\Actions\Pacientes;

use App\Models\Cola;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Llenar la historia** de un paciente que ya está dado de alta sin número: le asigna el
 * `numhistoria` del médico y deja la ficha lista para atender.
 *
 * Es la regla **"el número lo asigna el servidor, con el correlativo del médico"** (wiki
 * `04-historia-clinica.md`: "No se atiende sin historia") y vive **acá y en un solo lugar**, como las
 * Actions de agenda (PLAN-WEB.md, R3). La usan las dos superficies:
 *
 *  - el **sync del móvil**, cuando un teléfono manda la creación de `historias` al atender
 *    (`App\Sync\CreacionesClinicas`), y
 *  - la **web**, con la acción "Pasar a cita formal" del panel "Pacientes sin historia" de la agenda
 *    (WEB-2.11). El rótulo del escritorio está sobre un botón muerto (`w_hacer_cita.srw:801-805`);
 *    la acción real es la de `w_pasar_pacientes_n_reg_consulta_cita.srw`, que asigna un número nuevo
 *    (`:161-176`, la última del `DataWindow` + 1) y recién después enlaza la cita — eso es lo que se
 *    porta, sobre el modelo de AppDDR en vez de `paciente_no_regi` (decisión del 2026-10-10).
 *
 * Cuatro cosas que no son obvias y **son** la regla:
 *
 *  - **El número es el correlativo del médico**: la última de `historias` **más** las del pivote
 *    `medico_pacientes`, comparando como entero (la columna guarda texto), y + 1. La unicidad del
 *    esquema es `(reg_medico, numhistoria)`, así que dos caminos pueden calcular el mismo número: el
 *    que pierde **reintenta** con el siguiente en vez de romper.
 *  - **Es idempotente**: si el paciente ya tiene historia, devuelve la que tiene y no numera otra vez.
 *    Con eso el reintento del sync y el doble clic de la web terminan en el mismo lugar.
 *  - **La cita sigue siendo la misma fila**: las de `cola` que estaban ancladas al paciente por
 *    `paciente_sinhistoria_id` y sin número pasan a llevarlo. La agenda y el sync leen
 *    `cola.numhistoria`, así que esa columna es lo que hace que la cita pase a ser "formal" sin
 *    duplicarla ni reinsertarla.
 *  - **El `reg_medico` sale del vínculo del paciente**, nunca de un formulario: es el tenant, y una
 *    superficie no lo elige. Si el vínculo no lo tiene, cae al principal del médico.
 */
final class AsignarHistoria
{
    /** Intentos totales antes de dejar subir el error de la base (misma cuenta que el sync tenía). */
    private const INTENTOS = 3;

    /**
     * @param  Medico  $medico  el médico con el que el paciente está vinculado
     * @param  array<int,string>  $registrosMedicos  todos sus `reg_medico`, para leer el correlativo
     *                             completo (el suyo y los que tenga asignados)
     * @param  int  $pacienteId  `pacientes.id` de la cita que se quiere pasar a formal — el que la
     *                           cita ancla en `cola.paciente_sinhistoria_id`
     *
     * @throws InvalidArgumentException si ese paciente no es de este médico
     */
    public function ejecutar(Medico $medico, array $registrosMedicos, int $pacienteId): Historia
    {
        // Sin el vínculo no se numera: el número es **de este médico**, y un paciente de otro
        // consultorio no se toca (la web responde 404 con esto mismo).
        $relacion = MedicoPaciente::where('medico_id', $medico->id)
            ->where('paciente_id', $pacienteId)
            ->first();

        if (! $relacion) {
            throw new InvalidArgumentException('Ese paciente no es de este médico.');
        }

        $yaTiene = $this->historiaDe($medico, (int) $relacion->paciente_id);
        if ($yaTiene !== null) {
            return $yaTiene;
        }

        $regMedico = $relacion->reg_medico ?: $medico->regMedicoPrincipal();

        // Dos teléfonos (o el escritorio) llenando historias a la vez pueden calcular el mismo número:
        // la unicidad `(reg_medico, numhistoria)` hace que uno falle, y ese reintenta con el siguiente.
        for ($intento = 0; ; $intento++) {
            try {
                return DB::transaction(function () use ($relacion, $regMedico, $medico, $registrosMedicos) {
                    $numero = $this->siguienteNumero($medico, $registrosMedicos);

                    $historia = Historia::create([
                        'numhistoria' => (string) $numero,
                        'reg_medico'  => $regMedico,
                        'paciente_id' => $relacion->paciente_id,
                        'medico_id'   => $medico->id,
                    ]);

                    $relacion->numhistoria = (string) $numero;
                    $relacion->save();

                    // La cita "sin historia" se enlaza a la historia nueva sin cambiar de fila: es la
                    // misma cita, y lo que el escritorio y la agenda leen para saber que ya es formal.
                    Cola::where('paciente_sinhistoria_id', $relacion->paciente_id)
                        ->whereIn('reg_medico', $registrosMedicos)
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
     * El correlativo vigente del médico: la última de `historias` **más** las del pivote, + 1.
     *
     * Las dos vías son necesarias porque el legado deja la historia en las dos: `historias` es la que
     * llena el sync (y el escritorio, que sube pacientes) y `medico_pacientes.numhistoria` es el
     * vínculo por médico. El número se guarda como texto, así que se compara como entero en PHP —
     * `max()` sobre texto ordenaría "9" después de "10".
     *
     * @param  array<int,string>  $registrosMedicos
     */
    public function siguienteNumero(Medico $medico, array $registrosMedicos): int
    {
        $numeros = Historia::whereIn('reg_medico', $registrosMedicos)->pluck('numhistoria')
            ->merge(MedicoPaciente::where('medico_id', $medico->id)->pluck('numhistoria'))
            ->filter(fn ($n) => $n !== null && is_numeric($n))
            ->map(fn ($n) => (int) $n);

        return ((int) $numeros->max()) + 1;
    }

    /** La historia que el paciente **ya tiene** con este médico, o `null` si todavía no tiene ninguna. */
    private function historiaDe(Medico $medico, int $pacienteId): ?Historia
    {
        return Historia::where('paciente_id', $pacienteId)
            ->where('medico_id', $medico->id)
            ->first();
    }
}
