<?php

namespace App\Clinica\Agenda;

use App\Models\DiaNoLaborable;
use App\Models\Medico;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Los **días no laborables** del médico: feriados, congresos, fines de semana, otro consultorio o
 * quirófano (`cola_dia_no_labor`).
 *
 * El escritorio **aborta** el agendamiento cuando el día tiene motivo (`w_nueva_cita_7.srw:698-706`);
 * la web **avisa y deja decidir** (decisión del 2026-10-09): la excepción real —"hoy es feriado pero
 * el Dr. atiende igual"— no puede quedar sin camino, y es la misma regla que ya se usa con la cita
 * pendiente. Por eso el aviso se arma acá, en un solo lugar, y lo usan el formulario de la cita y la
 * agenda: la regla no se escribe dos veces (PLAN-WEB.md, R3).
 *
 * El `medico` de la tabla es la **clave de `evolucion`** (el mismo número que `cola.medico`), no
 * `medicos.id`; ver `Medico::claveDeEvolucion()`.
 */
class DiasNoLaborables
{
    /**
     * La clave con la que se marcan los días de este médico: la de `evolucion` o, si el consultorio
     * todavía no tiene esa configuración cargada (lo común en los datos reales), el id del médico.
     *
     * **La clave se busca en los registros del doctor**, no en el `reg_medico` del contexto (Fase 2 del
     * cambio de `reg_medico`): la fila de `evolucion` del médico vive donde él se configuró, y en un centro
     * —donde la marca del día sí es del centro— la clave tiene que seguir siendo la del doctor para que el
     * día marcado y la cita caigan bajo el mismo número. Es la misma resolución —y el mismo respaldo— que
     * usa `cola.medico` al agendar.
     */
    public function claveDe(Medico $medico): int
    {
        return $medico->claveDeEvolucion($medico->registrosDeTrabajo()) ?? $medico->id;
    }

    /**
     * El aviso del día no laborable ya armado para el médico del contexto, o `null` si ese día atiende
     * normal. Lo usan la agenda, el formulario de la cita y los avisos de las redirecciones.
     *
     * @param  array  $contexto  el `contexto_clinico` de la petición (`medico` y `reg_medico`)
     */
    public function avisoDelDia(array $contexto, Carbon $fecha): ?string
    {
        $dia = $this->delDia(
            $contexto['reg_medico'],
            $this->claveDe($contexto['medico']),
            $fecha,
        );

        return $dia !== null ? $this->aviso($dia) : null;
    }

    /** El día no laborable de esa fecha para ese médico, o `null` si atiende normal. */
    public function delDia(string $regMedico, int $claveMedico, Carbon $fecha): ?DiaNoLaborable
    {
        return DiaNoLaborable::where('reg_medico', $regMedico)
            ->where('medico', $claveMedico)
            ->whereDate('dia', $fecha->toDateString())
            ->first();
    }

    /**
     * Los elegidos de la clínica en un rango de fechas, indexados por `Y-m-d`. Sirve para pintar un
     * mes o una semana sin una consulta por día.
     *
     * @return Collection<string,DiaNoLaborable>
     */
    public function entre(string $regMedico, Carbon $desde, Carbon $hasta): Collection
    {
        return DiaNoLaborable::where('reg_medico', $regMedico)
            ->whereDate('dia', '>=', $desde->toDateString())
            ->whereDate('dia', '<=', $hasta->toDateString())
            ->orderBy('dia')
            ->get()
            ->keyBy(fn (DiaNoLaborable $dia) => $dia->dia->toDateString());
    }

    /**
     * Los días marcados de **un médico** en un mes, del 1 al último. Es la lista de la pantalla de días
     * no laborables (a diferencia de `entre`, que trae los de toda la clínica para pintar la agenda).
     *
     * @return Collection<int,DiaNoLaborable>
     */
    public function delMes(string $regMedico, int $claveMedico, Carbon $mes): Collection
    {
        return DiaNoLaborable::where('reg_medico', $regMedico)
            ->where('medico', $claveMedico)
            ->whereDate('dia', '>=', $mes->copy()->startOfMonth()->toDateString())
            ->whereDate('dia', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->orderBy('dia')
            ->get();
    }

    /** Un día del consultorio del contexto; el de otro `reg_medico` no se toca ni se confirma que exista. */
    public function delContexto(string $regMedico, int $id): DiaNoLaborable
    {
        return DiaNoLaborable::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * El aviso para el usuario, con el `motivo` del legado **palabra por palabra** (el escritorio
     * muestra el mismo texto en su `messagebox`).
     */
    public function aviso(DiaNoLaborable $dia): string
    {
        $fecha = $dia->dia->format(\App\Support\Regionalizacion::formatoActual());
        $motivo = trim((string) $dia->motivo);

        return $motivo === ''
            ? "El {$fecha} el médico tiene el día marcado como {$dia->etiquetaTipo()}."
            : "El {$fecha} el médico tiene: {$motivo}.";
    }
}
