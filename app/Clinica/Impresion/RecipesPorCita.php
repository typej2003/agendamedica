<?php

namespace App\Clinica\Impresion;

use App\Actions\Consulta\AbrirConsulta;
use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Consulta\PacienteDeLaCita;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Los récipes de una cita atendida** (WEB-5.2): de la cita de la agenda a la consulta que la atendió y a los
 * récipes que esa consulta tiene. Es lo que la secretaría necesita para imprimir el récipe del paciente que
 * acaba de salir del consultorio **sin entrar a la consulta** (la consulta es sólo del médico).
 *
 * ## El vínculo
 *
 * `cola` no tiene `nroconsulta`: la cita y la consulta se relacionan por **`numhistoria` + fecha** (wiki
 * `04-historia-clinica.md`). La consulta de una cita es **la del día de la cita con el número más alto** —la
 * misma que abre "Editar consulta" (`AbrirConsulta::delDia`)—, así que la secretaría imprime los récipes de la
 * consulta que el médico está viendo. El paciente se resuelve con `PacienteDeLaCita`, igual que al atender.
 *
 * Tiene dos lecturas, con la misma regla:
 *
 *  - `deLaCita(cita)` — **una** cita: el paciente y la consulta, para abrir la vista previa.
 *  - `conRecipes(citas)` — **varias** citas de la agenda, en lote (tres consultas, no una por fila): cuáles
 *    tienen al menos un récipe con medicamentos, para ofrecer el botón sólo donde hay qué imprimir.
 *
 * Es de **sólo lectura**.
 */
final class RecipesPorCita
{
    public function __construct(
        private PacienteDeLaCita $pacientes,
        private AbrirConsulta $consultas,
    ) {
    }

    /**
     * El paciente de una cita y la consulta del día en que se lo atendió: `pacienteId` y `nombre` (del paciente,
     * para el encabezado) y `consulta`, que es `null` si la cita no dice a quién atendió, el paciente todavía no
     * tiene historia o ese día no hubo consulta.
     *
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico del contexto
     * @return array{pacienteId:?int, nombre:string, consulta:?Consulta}
     */
    public function deLaCita(Cola $cita, array $registrosMedicos): array
    {
        $pacienteId = $this->pacientes->opcional($cita, $registrosMedicos);
        $paciente = $pacienteId === null ? null : Paciente::find($pacienteId);

        $nombre = $paciente === null
            ? 'el paciente de la cita'
            : trim(trim((string) $paciente->nombres) . ' ' . trim((string) $paciente->apellidos));

        $historia = $pacienteId === null ? null : $this->pacientes->historia($pacienteId, $registrosMedicos);
        $consulta = $historia === null ? null : $this->consultas->delDia($historia, Carbon::parse($cita->fecha));

        // La consulta tiene que ser del registro de la cita: la historia puede estar en otro registro del
        // mismo médico, y la agenda de acá es la de uno solo.
        if ($consulta !== null && $consulta->reg_medico !== $cita->reg_medico) {
            $consulta = null;
        }

        return ['pacienteId' => $pacienteId, 'nombre' => $nombre, 'consulta' => $consulta];
    }

    /**
     * Cuáles de las citas atendidas tienen récipes con medicamentos (los del paciente o el de la pareja).
     *
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @return array<int,int>  los ids de `cola` con algo para imprimir (como claves y valores)
     */
    public function conRecipes(string $regMedico, Collection $citas): array
    {
        $atendidas = $citas->filter(fn (CitaDeAgenda $cita) => $cita->atendida() && ! $cita->movida() && $cita->numHistoria !== null);

        if ($atendidas->isEmpty()) {
            return [];
        }

        // La consulta del día de cada cita (la de mayor número), por historia y fecha.
        $consultas = DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $atendidas->pluck('numHistoria')->unique()->all())
            ->whereIn(DB::raw('date(fecha)'), $atendidas->pluck('fecha')->unique()->all())
            ->get(['numhistoria', 'nroconsulta', 'fecha']);

        $delDia = [];
        foreach ($consultas as $consulta) {
            $clave = (int) $consulta->numhistoria . '|' . substr((string) $consulta->fecha, 0, 10);
            $delDia[$clave] = max($delDia[$clave] ?? 0, (int) $consulta->nroconsulta);
        }

        if ($delDia === []) {
            return [];
        }

        // Las consultas (historia|número) que tienen algún medicamento en un récipe del paciente o en el de la pareja.
        $conRecipe = [];
        foreach (['recipes', 'recipes_pareja'] as $tabla) {
            $filas = DB::table($tabla)
                ->where('reg_medico', $regMedico)
                ->whereIn('nrohistoria', $atendidas->pluck('numHistoria')->unique()->all())
                ->distinct()
                ->get(['nrohistoria', 'nroconsulta']);

            foreach ($filas as $fila) {
                $conRecipe[(int) $fila->nrohistoria . '|' . (int) $fila->nroconsulta] = true;
            }
        }

        $ids = [];
        foreach ($atendidas as $cita) {
            $nroconsulta = $delDia[$cita->numHistoria . '|' . $cita->fecha] ?? null;

            if ($nroconsulta !== null && isset($conRecipe[$cita->numHistoria . '|' . $nroconsulta])) {
                $ids[$cita->id] = $cita->id;
            }
        }

        return $ids;
    }
}
