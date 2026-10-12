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
 * **Los documentos de una cita atendida** (WEB-5.2, ampliado en WEB-5.3a): de la cita de la agenda a la consulta que la
 * atendió y a lo que esa consulta tiene para imprimir —los récipes y las solicitudes (constancia, reposo, referencias,
 * hospitalización, informe y dieta)—. Es lo que la secretaría necesita para entregarle los papeles al paciente que acaba de salir del consultorio
 * **sin entrar a la consulta** (la consulta es sólo del médico).
 *
 * ## El vínculo
 *
 * `cola` no tiene `nroconsulta`: la cita y la consulta se relacionan por **`numhistoria` + fecha** (wiki
 * `04-historia-clinica.md`). La consulta de una cita es **la del día de la cita con el número más alto** —la misma que abre
 * "Editar consulta" (`AbrirConsulta::delDia`)—, así que la secretaría imprime los documentos de la consulta que el médico
 * está viendo. El paciente se resuelve con `PacienteDeLaCita`, igual que al atender.
 *
 * Tiene dos lecturas, con la misma regla:
 *
 *  - `deLaCita(cita)` — **una** cita: el paciente y la consulta, para abrir la lista o la vista previa.
 *  - `conDocumentos(citas)` — **varias** citas de la agenda, en lote (unas pocas consultas, no una por fila): cuáles tienen
 *    algo para imprimir, para ofrecer el botón sólo donde hay qué imprimir.
 *
 * ## Qué cuenta como «algo para imprimir» en el lote
 *
 * Un récipe con medicamentos (del paciente o de la pareja) o un **documento de verdad** de los de `QueEsUnDocumento`: una
 * constancia con algo propio del médico, un reposo real, una referencia, un informe real, una orden de hospitalización o una dieta con texto. Es **la misma regla** que
 * `SolicitudesParaImprimir::disponibles` aplica a una sola consulta, escrita acá como consulta de conjunto (SQL) porque es
 * por toda la agenda; los tests comparan las dos formas.
 *
 * Es de **sólo lectura**.
 */
final class DocumentosPorCita
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
     * Cuáles de las citas atendidas tienen algo para imprimir.
     *
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @return array<int,int>  los ids de `cola` con algo para imprimir (como claves y valores)
     */
    public function conDocumentos(string $regMedico, Collection $citas): array
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

        $historias = $atendidas->pluck('numHistoria')->unique()->all();
        $conAlgo = [];

        // Los récipes del paciente o el de la pareja: cualquier medicamento.
        foreach (['recipes', 'recipes_pareja'] as $tabla) {
            $this->marcar($conAlgo, DB::table($tabla)->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        }

        // Las solicitudes que son documentos de verdad (`QueEsUnDocumento`, en su forma de conjunto):
        //  - constancia con algo propio del médico (observación, diagnósticos editados o título);
        $this->marcar($conAlgo, DB::table('constancia_obs')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
            ->where(fn ($q) => $q
                ->whereRaw("TRIM(COALESCE(observacion01, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(observacion, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(titulo, '')) <> ''")), 'numhistoria', 'numconsulta');
        //  - reposo de más de un día o con justificación;
        $this->marcar($conAlgo, DB::table('reposo_paciente')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias)
            ->where(fn ($q) => $q->where('numdias', '>', 1)->orWhereRaw("TRIM(COALESCE(obser_reposo, '')) <> ''")), 'nrohistoria', 'nroconsulta');
        //  - referencia (cualquiera);
        $this->marcar($conAlgo, DB::table('referencia')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        //  - informe con texto que no sea la plantilla `.`;
        $this->marcar($conAlgo, DB::table('informe')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias)
            ->whereRaw("TRIM(COALESCE(descripcion, '')) NOT IN ('', '.')"), 'nrohistoria', 'nroconsulta');
        //  - hospitalización con el lugar, el diagnóstico o las recomendaciones (WEB-5.3b: la fila vacía que crea el escritorio no cuenta);
        $this->marcar($conAlgo, DB::table('hospitalizacion')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias)
            ->where(fn ($q) => $q
                ->whereRaw("TRIM(COALESCE(hospital, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(diagprev, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(dieta, '')) <> ''")), 'nrohistoria', 'nroconsulta');
        //  - dieta con texto (la tabla llama `histroria` a la historia y `consulta` al número de la consulta).
        $this->marcar($conAlgo, DB::table('dieta_paciente')->where('reg_medico', $regMedico)->whereIn('histroria', $historias)
            ->whereRaw("TRIM(COALESCE(descripcion, '')) <> ''"), 'histroria', 'consulta');

        $ids = [];
        foreach ($atendidas as $cita) {
            $nroconsulta = $delDia[$cita->numHistoria . '|' . $cita->fecha] ?? null;

            if ($nroconsulta !== null && isset($conAlgo[$cita->numHistoria . '|' . $nroconsulta])) {
                $ids[$cita->id] = $cita->id;
            }
        }

        return $ids;
    }

    /**
     * Anota en `$conAlgo` las consultas (`historia|número`) que tienen al menos una fila de esa consulta.
     *
     * @param  array<string,bool>  $conAlgo
     */
    private function marcar(array &$conAlgo, $consulta, string $columnaHistoria, string $columnaConsulta): void
    {
        foreach ($consulta->distinct()->get([$columnaHistoria, $columnaConsulta]) as $fila) {
            $conAlgo[(int) $fila->{$columnaHistoria} . '|' . (int) $fila->{$columnaConsulta}] = true;
        }
    }
}
