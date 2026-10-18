<?php

namespace App\Clinica\Agenda;

use App\Clinica\Agenda\Vistas\FormularioDeCitaVista;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Models\Cola;
use Carbon\Carbon;

/**
 * Arma el formulario de la cita con todo lo que necesita: las sedes del médico, los bloques de la sede
 * ese día, la jornada (cupo y número que le toca al próximo), los motivos y el aviso de día no laborable.
 * Lo usan las tres pantallas que lo muestran: "Nueva cita", "Editar cita" y la repregunta por la cita
 * pendiente.
 */
class FormularioDeCita
{
    public function __construct(
        private ArmadorDeAgenda $armador,
        private AgendamientoDeCitas $agendamiento,
        private MotivosDeCita $motivos,
        private DiasNoLaborables $diasNoLaborables,
    ) {
    }

    /**
     * @param  array{cita: ?Cola, accion: string, fecha: Carbon, sedeId: ?int, valores: array, pacienteElegido: ?array, pendiente: ?array, volverA?: ?array}  $datos
     */
    public function armar(array $contexto, array $datos): FormularioDeCitaVista
    {
        $regMedico = $contexto['reg_medico'];
        $sedes = $this->armador->sedes($regMedico, $contexto['medico']->id);
        $fecha = $datos['fecha'];
        $sede = $datos['sedeId'] !== null ? $sedes->firstWhere('id', (int) $datos['sedeId']) : null;
        $bloques = $sede ? $sede->bloquesDe($fecha->dayOfWeekIso) : collect();

        $horaEfectiva = $this->agendamiento->horaEfectiva(
            $datos['valores']['hora'] ?? null,
            $datos['valores']['bloque'] ?? null,
            $bloques,
        );

        // La jornada de esa sede ese día: la vista muestra con ella el cupo y el número que le toca al
        // próximo paciente, que es el mismo que se le va a asignar al guardar.
        $jornada = $sede
            ? $this->armador->jornadaPara($regMedico, $sedes, $fecha, $sede->medical_center_id, $horaEfectiva)
            : null;

        return new FormularioDeCitaVista(
            citaId: $datos['cita']?->id,
            accion: $datos['accion'],
            fecha: $fecha,
            sedes: $sedes,
            sede: $sede,
            bloques: $bloques,
            jornada: $jornada,
            motivos: $this->motivos->de($regMedico, $sede),
            paciente: $datos['pacienteElegido'],
            pendiente: $datos['pendiente'],
            valores: $datos['valores'],
            // La marca es por médico: el aviso se arma con la clave del médico del contexto.
            avisoNoLaborable: $this->diasNoLaborables->avisoDelDia($contexto, $fecha),
            // En la zona del consultorio, como en `LectorDeAgenda`: el formulario decide con esto si la cita es de hoy.
            ahora: FechaDelConsultorio::ahora(),
            // A dónde vuelve si se llegó con "Hacer cita" desde los récipes (WEB-5.2); `null` = la agenda.
            volverA: $datos['volverA'] ?? null,
            // Sólo el médico administra el catálogo de motivos (WEB-8.1c): a la secretaría no se le ofrece un enlace con 403.
            puedeAdministrarMotivos: (bool) ($datos['puedeAdministrarMotivos'] ?? false),
            // El alta mínima del paso 2 arranca con el sexo de la especialidad, como el alta de la ficha (O-01).
            sexoPorDefectoDelNuevoPaciente: FormularioDePacienteVista::sexoPorDefectoDe($contexto['specialty'] ?? null),
        );
    }
}
