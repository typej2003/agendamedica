<?php

namespace App\Clinica\Agenda;

use App\Models\Cola;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arma la agenda de la web clínica (PLAN-WEB.md, F2): qué citas hay en un rango, en qué **jornada**
 * cae cada una y qué **posición** tiene el paciente.
 *
 * La lectura es directa sobre las tablas reales, como el módulo Pacientes, y las reglas están
 * **portadas del móvil** —el que ya las tiene probadas— en vez de inventadas:
 *
 *  - `sede.dart` (`Sedes.jornadaDe`, `bloqueDe`, `turnoDe`): la jornada y el turno;
 *  - `agenda_state.dart` (`posicionEnJornada`, `_compararEnJornada`): orden por modalidad y posición;
 *  - `agenda_repository.dart` (`resumenDeJornada`): el cupo se mide contra el bloque, no contra el día.
 *
 * No hay una tabla "jornada": se calcula acá, en un solo lugar, para que la web no tenga su propia
 * versión de la regla (PLAN-WEB.md, R3).
 */
final class ArmadorDeAgenda
{
    /**
     * Las sedes donde atiende el médico, con sus bloques y su centro, más las compartidas del
     * consultorio (las que no tienen dueño). Solo las activas.
     *
     * @return Collection<int,Office>
     */
    public function sedes(string $regMedico, ?int $medicoId = null): Collection
    {
        return Office::with(['schedules', 'medicalCenter'])
            ->where('activo', true)
            ->where(function ($consulta) use ($regMedico, $medicoId) {
                $consulta->where('reg_medico', $regMedico);
                if ($medicoId) {
                    $consulta->orWhere('medico_id', $medicoId);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Las citas del rango, con el nombre del paciente y la **sede resuelta**.
     *
     * Las citas del legado no traen `medical_center_id`: su sede se deduce de la historia del
     * paciente, que es el único enlace que tienen con un lugar. Misma regla que el móvil
     * (`agenda_repository.dart`, `_centroPorNumHistoria`). El paciente se resuelve por el pivote
     * `medico_pacientes` (el `numhistoria` es por médico) o, en una cita sin historia, por
     * `paciente_sinhistoria_id`.
     *
     * @return Collection<int,CitaDeAgenda>
     */
    public function citas(string $regMedico, Carbon $desde, Carbon $hasta): Collection
    {
        $filas = Cola::where('reg_medico', $regMedico)
            // `whereDate` y no `whereBetween`: con el cast `date` la fecha se guarda con hora
            // (`… 00:00:00`) y en SQLite `BETWEEN 'Y-m-d'` la dejaría afuera. `whereDate` resuelve
            // el día en los dos motores (en MySQL la columna es DATE y también coincide).
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->orderBy('fecha')
            ->orderBy('hora_ini')
            ->get();

        if ($filas->isEmpty()) {
            return collect();
        }

        $numHistorias = $filas->pluck('numhistoria')->filter()->unique()->values();
        $centroPorHistoria = $this->centroPorHistoria($regMedico, $numHistorias);
        $fichaPorHistoria = $this->fichaPorHistoria($regMedico, $numHistorias);
        $motivoPorCodigo = $this->motivoPorCodigo(
            $regMedico,
            $filas->pluck('tipo')->merge($filas->pluck('motivo'))->filter()->unique()->values(),
        );

        $sinHistoria = $filas->pluck('paciente_sinhistoria_id')->filter()->unique()->values();
        $fichaSinHistoria = $sinHistoria->isEmpty() ? [] : DB::table('pacientes')
            ->whereIn('id', $sinHistoria->all())
            ->get(['id', 'nombres', 'apellidos', 'cedula', 'telefono', 'email'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => [
                'id' => (int) $p->id,
                'nombre' => $this->nombreDe($p),
                'telefono' => $p->telefono,
                'email' => $p->email,
                'cedula' => $p->cedula,
            ]])
            ->all();

        return $filas->map(function (Cola $cola) use ($centroPorHistoria, $fichaPorHistoria, $fichaSinHistoria, $motivoPorCodigo) {
            $numHistoria = $cola->numhistoria !== null ? (int) $cola->numhistoria : null;
            $centroId = $cola->medical_center_id !== null
                ? (int) $cola->medical_center_id
                : ($numHistoria !== null ? ($centroPorHistoria[$numHistoria] ?? null) : null);

            $sinHistoriaId = $cola->paciente_sinhistoria_id !== null ? (int) $cola->paciente_sinhistoria_id : null;
            $ficha = $numHistoria !== null
                ? ($fichaPorHistoria[$numHistoria] ?? null)
                : ($sinHistoriaId !== null ? ($fichaSinHistoria[$sinHistoriaId] ?? null) : null);

            return new CitaDeAgenda(
                id: (int) $cola->id,
                fecha: Carbon::parse($cola->fecha)->toDateString(),
                horaIni: $cola->hora_ini,
                numOrden: $cola->numorden !== null ? (int) $cola->numorden : null,
                estado: $cola->estado !== null ? (int) $cola->estado : null,
                atendido: $cola->atendido !== null ? (int) $cola->atendido : null,
                monto: $cola->monto !== null ? (float) $cola->monto : null,
                montoPagado: $cola->monto_pagado !== null ? (float) $cola->monto_pagado : null,
                numHistoria: $numHistoria,
                pacienteSinHistoriaId: $sinHistoriaId,
                pacienteId: $ficha['id'] ?? null,
                paciente: $ficha['nombre'] ?? 'Paciente sin ficha',
                centroId: $centroId,
                motivo: $cola->motivo,
                tipo: $cola->tipo,
                medico: $cola->medico !== null ? (int) $cola->medico : null,
                movidaEscritorio: (bool) $cola->movida_escritorio,
                motivoNombre: $motivoPorCodigo[$cola->tipo ?? ''] ?? $motivoPorCodigo[$cola->motivo ?? ''] ?? null,
                // El contacto del paciente viaja con la cita: el envío del recordatorio (WEB-2.6) lo
                // necesita por fila y resolverlo en la vista sería una consulta por cita. La cédula la
                // usa el listado que se imprime (WEB-2.7).
                telefono: $ficha['telefono'] ?? null,
                email: $ficha['email'] ?? null,
                cedula: $ficha['cedula'] ?? null,
            );
        });
    }

    /**
     * Agrupa las citas en jornadas (fecha + sede + bloque), ordenadas por fecha, sede y bloque.
     *
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @param  Collection<int,Office>  $sedes
     * @return Collection<int,Jornada>
     */
    public function jornadas(Collection $citas, Collection $sedes): Collection
    {
        $grupos = [];

        foreach ($citas as $cita) {
            [$sede, $indice, $horario] = $this->jornadaDe($cita, $sedes);
            $clave = $cita->fecha . '#' . ($cita->centroId ?? 0) . '#' . $indice;

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'fecha'   => $cita->fecha,
                    'centro'  => $cita->centroId,
                    'indice'   => $indice,
                    'sede'    => $sede,
                    'horario' => $horario,
                    'citas'   => [],
                ];
            }

            $grupos[$clave]['citas'][] = $cita;
        }

        $jornadas = collect($grupos)->map(fn (array $grupo) => new Jornada(
            fecha: $grupo['fecha'],
            centroId: $grupo['centro'],
            indice: $grupo['indice'],
            sede: $grupo['sede'],
            horario: $grupo['horario'],
            citas: collect($grupo['citas']),
        ));

        return $jornadas
            ->sortBy(fn (Jornada $jornada) => sprintf(
                '%s#%010d#%03d',
                $jornada->fecha,
                $jornada->centroId ?? 0,
                $jornada->indice
            ))
            ->values();
    }

    /**
     * La posición de cada cita dentro de su jornada, por id de cita. Se calcula sobre **todas** las
     * citas del rango, no sobre las filtradas: si la secretaria filtra por motivo, el paciente 7
     * tiene que seguir siendo el 7 (regla del móvil).
     *
     * Las citas movidas por el escritorio quedan afuera: no tienen posición en la cola del día.
     *
     * @param  Collection<int,Jornada>  $jornadas
     * @return array<int,int>
     */
    public function posiciones(Collection $jornadas): array
    {
        $posiciones = [];

        foreach ($jornadas as $jornada) {
            foreach ($jornada->ordenadas() as $fila) {
                if ($fila['posicion'] === null) {
                    continue;
                }
                $posiciones[$fila['cita']->id] = $fila['posicion'];
            }
        }

        return $posiciones;
    }

    /**
     * La jornada de un día y una sede a una hora dada, **exista o no una cita todavía**: la usa
     * "Nueva cita" para saber la modalidad y el cupo de esa jornada y qué número le toca al próximo
     * paciente (`Jornada::siguienteNumero()`). Es la misma cuenta que después usa la agenda para
     * agrupar la cita recién creada: si no coincidieran, el número que se le dio no sería el que
     * se muestra.
     *
     * @param  Collection<int,Office>  $sedes
     */
    public function jornadaPara(
        string $regMedico,
        Collection $sedes,
        Carbon $fecha,
        ?int $centroId,
        ?string $hora
    ): Jornada {
        [$sede, $indice, $horario] = $this->ubicar($centroId, $fecha->toDateString(), $hora, $sedes);

        $citas = $this->citas($regMedico, $fecha->copy(), $fecha->copy())
            ->filter(function (CitaDeAgenda $cita) use ($centroId, $sedes, $indice) {
                if ($cita->centroId !== $centroId) {
                    return false;
                }

                [, $indiceDeLaCita] = $this->ubicar($cita->centroId, $cita->fecha, $cita->horaIni, $sedes);

                return $indiceDeLaCita === $indice;
            })
            ->values();

        return new Jornada($fecha->toDateString(), $centroId, $indice, $sede, $horario, $citas);
    }

    /**
     * La próxima cita **sin atender, de hoy en adelante** de un paciente: la regla de Alexander
     * (una sola cita pendiente por paciente, médico y consultorio) que "Nueva cita" usa para
     * preguntar qué hacer con la anterior. Portada del móvil (`agenda_repository.citaPendiente`):
     * las pasadas sin atender no cuentan —en el legado hay muchas inasistencias que nadie marcó— y
     * una de hoy ya atendida tampoco.
     *
     * Se excluyen las que el **escritorio movió** (`movida_escritorio`): su paciente ya está en la
     * fecha nueva, así que contarlas daría dos pendientes por una sola cita postergada.
     * (El móvil todavía las cuenta como pendientes: ver WEB-2.13.)
     *
     * El paciente se busca por las dos formas en que una cita se enlaza con él: `numhistoria` —el
     * camino normal— o `paciente_sinhistoria_id` —una cita de un paciente sin historia—.
     *
     * Devuelve la fila y su **sede ya resuelta** (`centroId`), que es la que hace falta para saber en
     * qué jornada está hoy y compararla con la nueva.
     *
     * @return array{cita: Cola, centroId: ?int}|null
     */
    public function citaPendiente(
        string $regMedico,
        ?int $numHistoria,
        ?int $pacienteId,
        ?int $centroId
    ): ?array {
        if ($numHistoria === null && $pacienteId === null) {
            return null;
        }

        $filas = Cola::where('reg_medico', $regMedico)
            ->whereDate('fecha', '>=', Carbon::today()->toDateString())
            ->where(function ($consulta) {
                $consulta->whereNull('atendido')->orWhere('atendido', 0);
            })
            ->where(function ($consulta) {
                $consulta->whereNull('movida_escritorio')->orWhere('movida_escritorio', 0);
            })
            ->where(function ($consulta) use ($numHistoria, $pacienteId) {
                if ($numHistoria !== null) {
                    $consulta->orWhere('numhistoria', $numHistoria);
                }
                if ($pacienteId !== null) {
                    $consulta->orWhere('paciente_sinhistoria_id', $pacienteId);
                }
            })
            ->orderBy('fecha')
            ->orderBy('hora_ini')
            ->get();

        if ($filas->isEmpty()) {
            return null;
        }

        $centroPorHistoria = $this->centroPorHistoria(
            $regMedico,
            $filas->pluck('numhistoria')->filter()->unique()->values(),
        );

        foreach ($filas as $fila) {
            // Las citas legadas no guardan la sede: sale de la historia del paciente, igual que al
            // leer la agenda.
            $centro = $fila->medical_center_id !== null
                ? (int) $fila->medical_center_id
                : ($fila->numhistoria !== null ? ($centroPorHistoria[(int) $fila->numhistoria] ?? null) : null);

            // Sin sede pedida no hay nada que comparar: sirve la primera pendiente.
            if ($centroId === null || $centro === $centroId) {
                return ['cita' => $fila, 'centroId' => $centro];
            }
        }

        return null;
    }

    /**
     * En qué jornada cae una cita: la sede de ese centro, el bloque del día que contiene su hora y
     * el horario configurado. Si no cae en ninguno, mañana o tarde según el mediodía.
     *
     * @param  Collection<int,Office>  $sedes
     * @return array{0: ?Office, 1: int, 2: ?OfficeSchedule}
     */
    private function jornadaDe(CitaDeAgenda $cita, Collection $sedes): array
    {
        return $this->ubicar($cita->centroId, $cita->fecha, $cita->horaIni, $sedes);
    }

    /**
     * Dónde cae una hora de un día: la sede del centro, el índice del bloque que la contiene y el
     * horario. Es el criterio del móvil (`Sedes.jornadaDe`) y el único lugar donde está escrito.
     *
     * @param  Collection<int,Office>  $sedes
     * @return array{0: ?Office, 1: int, 2: ?OfficeSchedule}
     */
    private function ubicar(?int $centroId, string $fecha, ?string $hora, Collection $sedes): array
    {
        $sede = $centroId !== null
            ? $sedes->firstWhere('medical_center_id', $centroId)
            : null;

        if ($sede) {
            $dia = Carbon::parse($fecha)->dayOfWeekIso;
            $bloques = $sede->schedules
                ->where('dia_semana', $dia)
                ->sortBy(fn (OfficeSchedule $bloque) => OfficeSchedule::aMinutos($bloque->hora_inicio))
                ->values();

            foreach ($bloques as $i => $bloque) {
                if ($bloque->contiene($hora)) {
                    return [$sede, $i, $bloque];
                }
            }
        }

        // Fuera de todo bloque: mañana (0) o tarde (1) según el mediodía.
        $minutos = OfficeSchedule::aMinutos($hora) ?? 0;

        return [$sede, $minutos < 12 * 60 ? 0 : 1, null];
    }

    /** @return array<int,int> numhistoria => medical_center_id (solo las que tienen sede). */
    private function centroPorHistoria(string $regMedico, Collection $numHistorias): array
    {
        if ($numHistorias->isEmpty()) {
            return [];
        }

        return DB::table('historias')
            ->where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $numHistorias->all())
            ->whereNotNull('medical_center_id')
            ->pluck('medical_center_id', 'numhistoria')
            ->mapWithKeys(fn ($centro, $historia) => [(int) $historia => (int) $centro])
            ->all();
    }

    /**
     * `codigo` => `tipo_atencion` de los motivos de cita del médico, para las filas que se leyeron.
     *
     * Se resuelve en lote y no cita por cita: un mes de agenda son cientos de filas y el catálogo es
     * chico. Un código que no está en el catálogo simplemente no aparece, y la cita muestra el
     * código crudo (`CitaDeAgenda::razon()`).
     *
     * @param  Collection<int,string>  $codigos
     * @return array<string,string>
     */
    private function motivoPorCodigo(string $regMedico, Collection $codigos): array
    {
        if ($codigos->isEmpty()) {
            return [];
        }

        return DB::table('motivo_cita')
            ->where('reg_medico', $regMedico)
            ->whereIn('codigo', $codigos->all())
            ->pluck('tipo_atencion', 'codigo')
            ->mapWithKeys(fn ($nombre, $codigo) => [(string) $codigo => (string) $nombre])
            ->all();
    }

    /** @return array<int,array{id:int,nombre:string,telefono:?string,email:?string,cedula:?string}> numhistoria => ficha. */
    private function fichaPorHistoria(string $regMedico, Collection $numHistorias): array
    {
        if ($numHistorias->isEmpty()) {
            return [];
        }

        return DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $regMedico)
            ->whereIn('medico_pacientes.numhistoria', $numHistorias->all())
            ->get([
                'medico_pacientes.numhistoria',
                'pacientes.id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.cedula',
                'pacientes.telefono',
                'pacientes.email',
            ])
            ->mapWithKeys(fn ($p) => [(int) $p->numhistoria => [
                'id' => (int) $p->id,
                'nombre' => $this->nombreDe($p),
                'telefono' => $p->telefono,
                'email' => $p->email,
                'cedula' => $p->cedula,
            ]])
            ->all();
    }

    private function nombreDe($paciente): string
    {
        return trim(($paciente->apellidos ?? '') . ', ' . ($paciente->nombres ?? ''), ', ');
    }
}
