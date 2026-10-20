<?php

namespace App\Sync\Escritorio;

use App\Models\Historia;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use App\Models\SyncCarga;

/**
 * Un paciente del legado -> como lo usa el app: paciente (compartido entre médicos, por cédula) +
 * relación con el médico (`medico_pacientes`) + historia. Mismo modelo que SyncAppDataController.
 *
 * En el legado el paciente se identifica por su número de historia; acá la historia del API
 * guarda ese número del escritorio en `clave_escritorio`, que puede diferir de `numhistoria` si el
 * app y el escritorio usaron el mismo número para pacientes distintos.
 */
class PacientesLegado
{
    public function __construct(private TablasLegado $tablas)
    {
    }

    /**
     * @param  array  $fila  fila de `pacientes` tal como la manda el escritorio
     * @param  int|string  $numHistoriaApi  número de historia que va a tener en el API
     * @param  string  $claveEscritorio  número de historia en el escritorio
     * @param  array  $cambiadas  (salida) columnas del paciente que cambiaron de valor => valor nuevo
     */
    public function guardar(SyncCarga $carga, array $fila, $numHistoriaApi, string $claveEscritorio, array &$ignoradas, array &$cambiadas = []): Historia
    {
        // `numhistoria` vive en la relación con el médico y en la historia. En algunas bases del
        // API `pacientes` todavía tiene esa columna (NOT NULL, heredada del dump del legado): si
        // existe se llena con el número del API; si no, no se informa como columna perdida.
        $sinUsar = [];
        $datos = $this->tablas->filtrar('pacientes', $this->tablas->normalizarClaves($fila), $sinUsar);
        foreach (array_diff($sinUsar, ['numhistoria']) as $columna) {
            if (! in_array($columna, $ignoradas, true)) {
                $ignoradas[] = $columna;
            }
        }
        unset($datos['user_id'], $datos['password']);
        if (array_key_exists('numhistoria', $datos)) {
            $datos['numhistoria'] = $numHistoriaApi;
        }

        // 1) La historia de este médico que ya corresponde a ese número del escritorio.
        $historia = Historia::where('reg_medico', $carga->reg_medico)
            ->where('clave_escritorio', $claveEscritorio)
            ->first();
        $paciente = $historia ? Paciente::find($historia->paciente_id) : null;

        // 2) Si no, el paciente compartido por cédula. Un paciente SIN cédula no se mezcla con otros
        //    sin cédula: es uno nuevo.
        $cedula = trim((string) ($fila['cedula'] ?? ''));
        if (! $paciente && $cedula !== '' && $cedula !== '0') {
            $paciente = Paciente::where('cedula', $cedula)->first();
        }
        if ($cedula === '' || $cedula === '0') {
            $datos['cedula'] = null;
        }

        $paciente = $paciente ?? new Paciente();
        $nuevo = ! $paciente->exists;
        $paciente->forceFill($datos);

        // `pacientes.reg_medico` es el tenant del **primer** vínculo del paciente (migración del 2026-10-20):
        // se completa al crear la ficha, nunca al actualizar una que ya era de otro consultorio.
        if ($nuevo && trim((string) $paciente->reg_medico) === '') {
            $paciente->reg_medico = $carga->reg_medico;
        }

        $cambiadas = $paciente->exists ? array_diff_key($paciente->getDirty(), array_flip(['updated_at', 'created_at'])) : [];
        $paciente->save();

        // El vínculo es del **consultorio** (`(reg_medico, paciente_id)`, migración del 2026-10-20), no del
        // médico. Si el paciente ya estaba vinculado a este registro —lo vinculó la web o el centro— se
        // conserva su `medico_id` y sólo se refresca la copia del número que lee el app viejo.
        $vinculo = MedicoPaciente::firstOrNew([
            'reg_medico'  => $carga->reg_medico,
            'paciente_id' => $paciente->id,
        ]);

        if (! $vinculo->exists) {
            $vinculo->medico_id = $carga->medico_id;
        }

        $vinculo->numhistoria = $numHistoriaApi;
        $vinculo->save();

        // La historia también es la del consultorio: se busca por `(reg_medico, paciente_id)`, no por el
        // médico que la sube (el mismo paciente en dos consultorios tiene una historia en cada uno).
        $historia = $historia ?? Historia::firstOrNew(['reg_medico' => $carga->reg_medico, 'paciente_id' => $paciente->id]);
        $historia->forceFill([
            'paciente_id'      => $paciente->id,
            'medico_id'        => $carga->medico_id,
            'numhistoria'      => (string) $numHistoriaApi,
            'reg_medico'       => $carga->reg_medico,
            'clave_escritorio' => $claveEscritorio,
        ])->save();

        return $historia;
    }
}
