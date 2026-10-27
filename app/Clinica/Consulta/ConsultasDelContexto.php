<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\DuenoDeLaHistoria;
use App\Models\Consulta;
use App\Models\MedicoPaciente;
use App\Models\MotivoConsultaPaciente;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * **La consulta del contexto** (F4/WEB-4.1, ampliada en WEB-4.2): resuelve de quién es una consulta y
 * arma las dos mitades del ViewModel del shell —el paciente del encabezado y su número de historia.
 *
 * La regla es una sola y la comparten la pantalla y **todas** las acciones que escriben sobre la
 * consulta: se filtra por los `reg_medico` del contexto **y** por el vínculo del paciente
 * (`medico_pacientes`), que es lo que dice de quién es la historia. Una consulta de otro consultorio
 * **no existe** —404, sin confirmar que exista—, y una fila de `motivo_consulta_paciente` de otro médico
 * tampoco se toca. Es la misma vara que la ficha y la historia del paciente.
 *
 * El número de historia se lee **del pivote**, que es donde vive el número de este médico (como texto y
 * con sus ceros), y se compara con el entero de `consultas` casteando: el legado escribe el número de
 * las dos formas (`01001` en el vínculo, `1001` en la consulta).
 */
final class ConsultasDelContexto
{
    /**
     * La consulta por su id, o excepción de "no encontrado" si es de otro médico.
     *
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico del contexto
     * @param  string|null  $regMedico  el `reg_medico` del vínculo (el del contexto de trabajo): acota
     *                                  además a ese registro, que es lo que la ficha y la agenda llaman
     *                                  "el consultorio del contexto"
     *
     * @throws ModelNotFoundException
     */
    public function consulta(array $registrosMedicos, int $consultaId, ?string $regMedico = null): Consulta
    {
        $consulta = Consulta::whereIn('reg_medico', $registrosMedicos)->find($consultaId);

        if ($consulta === null || ($regMedico !== null && $consulta->reg_medico !== $regMedico)) {
            throw (new ModelNotFoundException())->setModel(Consulta::class, [$consultaId]);
        }

        return $consulta;
    }

    /**
     * El paciente de la historia de una consulta, con el `numhistoria` **de la consulta**: `null` si la
     * consulta quedó huérfana (sin un paciente dueño del número).
     *
     * **La regla es la de `DuenoDeLaHistoria`, y primero dentro del registro de la consulta** (2026-10-19):
     * el número es único por registro, y buscar el vínculo en todos los registros del médico con un
     * `whereIn(...)->first()` devolvía el primero que coincidiera. Con dos pacientes que comparten número
     * (el importador de SQL del `/admin`, un script) la agenda nombraba a uno —el último vínculo— y el
     * encabezado de la consulta al otro —el primero—: el médico veía «DIAZ, ALEXANDER · H. 21» en la
     * agenda y «KARLA RONDON · H. 21» al atenderlo. Ahora los dos nombran al mismo, que es el dueño del
     * número en `historias`.
     *
     * @param  array<int,string>  $registrosMedicos
     */
    public function paciente(Consulta $consulta, array $registrosMedicos): ?object
    {
        $paciente = null;

        $duenio = (new DuenoDeLaHistoria())->de($consulta->reg_medico, $consulta->numhistoria);

        if ($duenio !== null) {
            $paciente = Paciente::find($duenio);
        }

        // Tolerancia con las consultas viejas cuyo número sólo está en el vínculo de otro registro del
        // médico: se sigue mirando el resto, pero **sólo** si el registro de la consulta no tiene a nadie.
        if ($paciente === null) {
            $pacienteId = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)
                ->where('numhistoria', (string) (int) $consulta->numhistoria)
                ->orderBy('id')
                ->value('paciente_id');

            $paciente = $pacienteId !== null ? Paciente::find((int) $pacienteId) : null;
        }

        if ($paciente === null) {
            return null;
        }

        return (object) [
            'id' => (int) $paciente->id,
            'nombres' => $paciente->nombres,
            'apellidos' => $paciente->apellidos,
            // La edad del encabezado de pediatría se cuenta con esto (`ConsultaShellVista::edad()`): sin la fecha el encabezado decía
            // «sin fecha de nacimiento» aunque el paciente la tuviera (Alexander, 2026-10-26).
            'fnacimiento' => $paciente->getRawOriginal('fnacimiento'),
            'numhistoria' => (string) (int) $consulta->numhistoria,
        ];
    }

    /**
     * **Las consultas de la misma historia** (WEB-11.5, el navegador 1…N): el número de cada una, en orden. Es la misma vara que la
     * consulta —el `reg_medico` del contexto y el mismo `numhistoria`—, así que no se ve ninguna que la pantalla no abriría.
     *
     * @return \Illuminate\Support\Collection<int,object{id:int, nroconsulta:int, fecha:?string}>
     */
    public function delPaciente(Consulta $consulta): \Illuminate\Support\Collection
    {
        return Consulta::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', $consulta->numhistoria)
            ->orderBy('nroconsulta')
            ->orderBy('id')
            ->get(['id', 'nroconsulta', 'fecha'])
            ->map(fn (Consulta $fila) => (object) [
                'id' => (int) $fila->id,
                'nroconsulta' => (int) $fila->nroconsulta,
                'fecha' => $fila->fecha ? substr((string) $fila->fecha, 0, 10) : null,
            ])
            ->values();
    }

    /**
     * La fila de `motivo_consulta_paciente` de una consulta, para quitarla. Se exige que cuelgue **de
     * esa** consulta: una fila de otro médico —o de otra consulta del mismo médico— no se toca.
     *
     * @throws ModelNotFoundException
     */
    public function motivoDeLaConsulta(Consulta $consulta, int $motivoId): int
    {
        $existe = MotivoConsultaPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->whereKey($motivoId)
            ->exists();

        if (! $existe) {
            throw (new ModelNotFoundException())->setModel(MotivoConsultaPaciente::class, [$motivoId]);
        }

        return $motivoId;
    }
}
