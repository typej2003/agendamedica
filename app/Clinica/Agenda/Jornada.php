<?php

namespace App\Clinica\Agenda;

use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Support\Collection;

/**
 * Una jornada: **fecha + sede + bloque del día**. Es lo que define hasta dónde llega un número de
 * paciente —cada jornada arranca en 1— y contra qué se mide el cupo.
 *
 * Portada del móvil (`lib/features/agenda/data/models/sede.dart`, `Jornada` y `Sedes.jornadaDe`):
 * la jornada no es una tabla, es un concepto **calculado** a partir de `offices` +
 * `office_schedules` + la hora de la cita. Si la hora no cae en ningún bloque configurado (cita
 * vieja, sede sin horarios, cita a mano fuera de horario) se cae a mañana o tarde según el
 * mediodía, la misma partición que el legado guarda en `turno` (`D`/`T`), para que ninguna cita
 * quede sin jornada.
 */
final class Jornada
{
    /**
     * @param  Collection<int,CitaDeAgenda>  $citas
     */
    public function __construct(
        public readonly string $fecha,          // Y-m-d
        public readonly ?int $centroId,         // medical_center_id de la sede
        public readonly int $indice,            // índice del bloque dentro del día (0 = el primero)
        public readonly ?Office $sede,
        public readonly ?OfficeSchedule $horario,
        public readonly Collection $citas,
    ) {
    }

    public function clave(): string
    {
        return $this->fecha . '#' . ($this->centroId ?? 0) . '#' . $this->indice;
    }

    public function modalidad(): string
    {
        return $this->sede->modalidad ?? Office::MODALIDAD_HORA;
    }

    public function esPorOrdenDeLlegada(): bool
    {
        return $this->modalidad() === Office::MODALIDAD_ORDEN;
    }

    /** `D` (mañana) o `T` (tarde): la convención del legado, derivada de la hora — no se pregunta. */
    public function turno(): string
    {
        $hora = $this->horario->hora_inicio ?? ($this->citas->first()->horaIni ?? null);

        return (OfficeSchedule::aMinutos($hora) ?? 0) < 12 * 60 ? 'D' : 'T';
    }

    public function cupo(): ?int
    {
        return $this->horario->cupo ?? null;
    }

    public function cantidad(): int
    {
        return $this->citas->count();
    }

    public function siguienteNumero(): int
    {
        return $this->cantidad() + 1;
    }

    /**
     * ¿Llegó al cupo? **Es un aviso, no un bloqueo**: sin cupo configurado (el estado de todos los
     * datos reales) nunca está llena, y con cupo se sigue pudiendo agendar — igual que en el móvil.
     */
    public function llena(): bool
    {
        $cupo = $this->cupo();

        return $cupo !== null && $this->cantidad() >= $cupo;
    }

    public function nombreSede(): string
    {
        return $this->sede->medicalCenter->name
            ?? $this->sede->office_number
            ?? 'Sin sede';
    }

    public function horarioTexto(): string
    {
        if (! $this->horario) {
            return 'Sin horario configurado';
        }

        return substr($this->horario->hora_inicio, 0, 5) . ' a ' . substr($this->horario->hora_fin, 0, 5);
    }

    /**
     * Las citas ordenadas y con su **posición** (1…n), que es lo que se muestra: la posición se
     * calcula, nunca se lee de `numorden` (que tiene huecos de citas eliminadas y numeraciones del
     * legado que no arrancan en 1).
     *
     * El criterio de orden es el del móvil (`_compararEnJornada`): si la sede trabaja **por orden
     * de llegada** manda `numorden`; en una sede con **hora de cita** manda la hora.
     *
     * @return Collection<int,array{cita: CitaDeAgenda, posicion: int}>
     */
    public function ordenadas(): Collection
    {
        $porOrdenDeLlegada = $this->esPorOrdenDeLlegada();

        $ordenadas = $this->citas->sort(function (CitaDeAgenda $a, CitaDeAgenda $b) use ($porOrdenDeLlegada) {
            if ($porOrdenDeLlegada) {
                $ordenA = $a->numOrden ?? PHP_INT_MAX;
                $ordenB = $b->numOrden ?? PHP_INT_MAX;
                if ($ordenA !== $ordenB) {
                    return $ordenA <=> $ordenB;
                }
            }

            return strcmp((string) $a->horaIni, (string) $b->horaIni);
        })->values();

        return $ordenadas->map(fn (CitaDeAgenda $cita, int $i) => ['cita' => $cita, 'posicion' => $i + 1]);
    }
}
