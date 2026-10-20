<?php

namespace App\Clinica\Consulta;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CitaNoAtendible;
use App\Actions\Agenda\CrearCita;
use App\Actions\Consulta\AbrirConsulta;
use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Agenda\DiasNoLaborables;
use App\Clinica\Agenda\Jornada;
use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\Office;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * **Atender al paciente que acaba de quedar atendible** (O-05, 2026-10-17): lo que el escritorio hacía al
 * pasar un paciente a la consulta apenas se le asignaba la historia. Es el diálogo «¿Atender ahora?» de la
 * ficha y del alta web, y su regla es la del legado:
 *
 *  1. Si el paciente **tiene una cita hoy sin atender**, se abre **esa** consulta (el mismo camino del
 *     mostrador: `AbrirConsulta` + `AtenderCita`). No se crea ninguna cita.
 *  2. Si **no tiene cita hoy**, se le **crea la cita del día** y se abre la consulta, como el legado. La
 *     cita nace por `CrearCita` —nunca por un `INSERT` propio— con el lugar que resuelve `ArmadorDeAgenda`
 *     (la **sede del contexto**, el bloque del día, la hora del bloque y el número de la jornada), en la
 *     modalidad del consultorio elegido, **confirmada** (el paciente ya está en el consultorio) y con su
 *     rastro `source = web`, igual que cualquier cita de la web.
 *  3. Si el día **no es laborable** o el consultorio **no tiene bloques** para hoy, **no se inventa la
 *     cita**: se devuelve el motivo y el paciente queda con su historia, para agendarlo a mano. Tampoco se
 *     crea nada si la barra superior no tiene un consultorio elegido: la cita necesita un lugar.
 *
 * Por qué es un servicio de la web y no una Action compartida: crea una cita **con el contexto del
 * consultorio** (la sede elegida en la barra), que es justo lo que el sync no tiene. Las dos escrituras
 * —la cita y la consulta— siguen viviendo en sus Actions (`CrearCita`, `AbrirConsulta`, `AtenderCita`),
 * que son las que comparten las superficies.
 */
final class AtencionInmediata
{
    public function __construct(
        private AbrirConsulta $abrir,
        private AtenderCita $atenderCita,
        private CrearCita $crearCita,
        private ArmadorDeAgenda $armador,
        private DiasNoLaborables $diasNoLaborables,
    ) {
    }

    /**
     * @param  array  $contexto  el `contexto_clinico` de la petición (`medico`, `reg_medico`, `office`…)
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico, como en el resto de la web
     * @param  Historia  $historia  la historia que el paciente acaba de recibir (o que ya tenía)
     */
    public function ejecutar(array $contexto, array $registrosMedicos, Historia $historia): ResultadoDeAtencion
    {
        // El día es **el del consultorio**, no el del servidor (trampa 59): es el mismo "hoy" con el que se
        // numeró la historia y con el que la agenda arma la jornada.
        $hoy = FechaDelConsultorio::hoy();

        $cita = $this->citaDeHoy($registrosMedicos, $historia, $hoy);
        $creada = false;

        if ($cita === null) {
            $creada = true;
            $resultado = $this->crearLaCitaDelDia($contexto, $historia, $hoy);

            if ($resultado instanceof ResultadoDeAtencion) {
                return $resultado;
            }

            $cita = $resultado;
        }

        try {
            // El mismo camino del diálogo «Abrir la consulta» de la agenda: re-atender reusa la consulta
            // del día si el paciente ya tiene una.
            $abierta = $this->abrir->abrir($cita, $registrosMedicos, $hoy);
        } catch (InvalidArgumentException $excepcion) {
            return ResultadoDeAtencion::noSePudo($excepcion->getMessage());
        }

        // La marca va **después** de abrir: no se marca una cita cuya consulta no se pudo abrir (la misma
        // regla del mostrador). Atender implica confirmar, y eso no se deshace. Una cita que ya estaba
        // atendida no se vuelve a marcar: se entra a su consulta, como «Editar consulta» en la agenda.
        if ((int) $cita->atendido !== 1) {
            try {
                $this->atenderCita->ejecutar($cita, true, FechaDelConsultorio::ahora(), 'web');
            } catch (CitaNoAtendible $excepcion) {
                return ResultadoDeAtencion::noSePudo($excepcion->getMessage());
            }
        }

        return ResultadoDeAtencion::abierta($abierta, $creada);
    }

    /**
     * La cita de **hoy** de este paciente: la primera **sin atender** por hora y, si todas están atendidas,
     * la primera de hoy. Las movidas por el escritorio no cuentan (su paciente ya está en la fecha nueva).
     *
     * Se mira cualquier cita de hoy —atendida o no— a propósito: volver a pedir «Atender ahora» no puede
     * crear una segunda cita para el mismo paciente el mismo día. Si la de hoy ya está atendida, se abre su
     * consulta y no se crea nada (es el «Editar consulta» de la agenda).
     *
     * @param  array<int,string>  $registrosMedicos
     */
    private function citaDeHoy(array $registrosMedicos, Historia $historia, CarbonInterface $hoy): ?Cola
    {
        $citas = Cola::whereIn('reg_medico', $registrosMedicos)
            ->whereDate('fecha', $hoy->toDateString())
            ->where(function ($consulta) {
                $consulta->whereNull('movida_escritorio')->orWhere('movida_escritorio', 0);
            })
            ->where(function ($consulta) use ($historia) {
                $consulta->where('numhistoria', (int) $historia->numhistoria);

                if ($historia->paciente_id !== null) {
                    $consulta->orWhere('paciente_sinhistoria_id', (int) $historia->paciente_id);
                }
            })
            ->orderBy('hora_ini')
            ->get();

        return $citas->first(fn (Cola $cita) => (int) $cita->atendido !== 1) ?? $citas->first();
    }

    /**
     * La cita que se le crea al paciente que llegó sin cita, o el motivo por el que no se puede.
     *
     * El lugar y la hora salen de la **sede del contexto** y de su **bloque del día**; el número, de la
     * jornada que arma `ArmadorDeAgenda` (la misma cuenta que después lee la agenda para agrupar la cita).
     * Si la sede no tiene bloques para hoy la cita se rechaza: una cita "a las 08:00 por defecto" en un día
     * que el consultorio no abre es un dato inventado.
     */
    private function crearLaCitaDelDia(array $contexto, Historia $historia, CarbonInterface $hoy): Cola|ResultadoDeAtencion
    {
        $medico = $contexto['medico'];
        $regMedico = $contexto['reg_medico'];
        $office = $contexto['office'] ?? null;

        if (! $office instanceof Office) {
            return ResultadoDeAtencion::noSePudo(
                'No hay un consultorio elegido en la barra superior: elige uno para poder crear la cita de hoy, '
                . 'o agenda al paciente a mano desde la agenda.'
            );
        }

        // Día no laborable: el escritorio aborta el agendamiento; la web avisa y deja decidir (WEB-2.8). Acá
        // **no se crea nada**: el aviso explica el motivo y el paciente queda con su historia.
        if ($this->diasNoLaborables->delDia($regMedico, $this->diasNoLaborables->claveDe($medico), $hoy) !== null) {
            return ResultadoDeAtencion::noSePudo(
                $this->diasNoLaborables->avisoDelDia($contexto, $hoy)
                . ' No se le crea la cita de hoy: el paciente quedó con su historia y puedes agendarlo a mano desde la agenda.'
            );
        }

        $bloques = $office->bloquesDe($hoy->dayOfWeekIso);

        if ($bloques->isEmpty()) {
            return ResultadoDeAtencion::noSePudo(
                'El consultorio de la barra superior no tiene horario para hoy, así que no se le puede crear la cita del día. '
                . 'El paciente quedó con su historia: agenda la cita a mano desde la agenda.'
            );
        }

        $hora = substr((string) $bloques->first()->hora_inicio, 0, 5);
        $sedes = $this->armador->sedes($regMedico);
        $jornada = $this->armador->jornadaPara($regMedico, $sedes, $hoy->copy(), $office->medical_center_id, $hora);

        return $this->crearCita->ejecutar($regMedico, [
            'fecha'             => $hoy->toDateString(),
            'hora_ini'          => $hora,
            'turno'             => Jornada::turnoDe($hora),
            'numorden'          => $jornada->siguienteNumero(),
            'medical_center_id' => $office->medical_center_id,
            'numhistoria'       => (int) $historia->numhistoria,
            // La clave legacy es la del **doctor** y se busca en sus registros, no en el `reg_medico` del
            // contexto (Fase 2 del cambio de `reg_medico`); el mismo criterio que `AgendamientoDeCitas`.
            'medico'            => $medico->claveDeEvolucion($medico->registrosDeTrabajo()),
            'atendido'          => 0,
            // Nace confirmada: el paciente ya está en el consultorio (decisión de O-05).
            'estado'            => Cola::ESTADO_CONFIRMADA,
        ], FechaDelConsultorio::ahora(), 'web');
    }
}
