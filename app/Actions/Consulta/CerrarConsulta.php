<?php

namespace App\Actions\Consulta;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CitaNoAtendible;
use App\Clinica\Consulta\CitaDeLaConsulta;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use Carbon\CarbonInterface;

/**
 * **Terminar la consulta** (WEB-4.8): la acción primaria del shell de la consulta, que cierra la atención
 * y deja la cita de la agenda como atendida.
 *
 * ## Qué hace el escritorio al terminar la atención (la fuente)
 *
 * **Nada propio de "terminar".** El PowerBuilder marca la cita al **abrir** la consulta, no al cerrarla:
 * el `open` de `w_consulta_principal` hace `UPDATE "cola" SET "atendido" = 1 WHERE numhistoria = :historia
 * AND fecha = today() AND atendido = 0` (`w_consulta_principal.srw:4416-4421`) y los botones de salida
 * sólo cierran la ventana —*Guardar y Salir* guarda la consulta y hace `close(w_consulta_principal)`
 * (`:6516-6518`), *Salir* hace `close()` sin más (`:6478`) y *Salir de la consulta* es el tooltip de ese
 * mismo botón (`:6475`)—. **No escribe ninguna hora de salida ni ningún otro estado**: la columna
 * `cola.atendido` vuelve a `0` por el "Cambiar cita"/"No atender" de la lista del día
 * (`w_pacientes_a_atender_hoy.srw:554`, `:684`) y toma `2` = "movida" al postergar la cita (`:395-398`),
 * pero no hay un valor de "terminada".
 *
 * Por eso el cierre **no inventa un estado**: es terminar la atención —la cita atendida, que es lo único
 * que la fuente escribe— y la regla que le da sentido al conjunto es la del servidor, "cita atendida ⇒
 * tiene historia" (PLAN-WEB.md §3.13 y TODO.md §9), que vive en `AtenderCita`.
 *
 * ## Qué hace esta Action
 *
 * Resuelve la cita del día (`CitaDeLaConsulta`), delega la marca en `AtenderCita` —la Action compartida
 * con el sync del móvil, con la regla y con su rastro en `sync_changes` (`source = web`)— y devuelve qué
 * pasó. **No crea ninguna consulta ni ningún estado nuevo** y **no borra nada**: la consulta ya existe
 * (se está mirando) y sus datos quedan donde están.
 *
 * Los tres desenlaces (ver `ConsultaCerrada`) y el error:
 *
 *  - **Hay cita**: se marca atendida. Es el camino normal del mostrador.
 *  - **No hay cita**: la consulta se abrió desde la historia del paciente, sin agenda detrás (o el
 *    escritorio borró la cita). Se informa, sin marcar nada y sin que la pantalla se rompa.
 *  - **La cita la movió el escritorio**: esa fila es la vieja y el paciente ya está en la fecha nueva; no
 *    se marca (misma vara que el resto de la web, `MostradorController::citaPorAtender`).
 *  - **La cita no tiene historia**: `AtenderCita` lanza `CitaNoAtendible`. La web no puede llegar acá
 *    —`AbrirConsulta` exige historia para abrir una consulta, así que toda consulta abierta tiene una—,
 *    pero la Action no lo da por sentado: la regla es del servidor y se defiende sola.
 *
 * @throws CitaNoAtendible
 */
final class CerrarConsulta
{
    public function __construct(
        private CitaDeLaConsulta $citas,
        private AtenderCita $atenderCita,
    ) {
    }

    /**
     * @param  int  $pacienteId  `pacientes.id` del paciente de la consulta: es el ancla de la cita que
     *                           nació sin número (el segundo intento de `CitaDeLaConsulta`)
     * @param  int|null  $sede  `medical_center_id` de la consulta, si se conoce: distingue dos citas del
     *                          mismo paciente el mismo día en dos consultorios
     * @param  CarbonInterface  $cuando  el "ahora" del consultorio (`FechaDelConsultorio::ahora()`), que
     *                                   es el que resuelve el last-write-wins por columna del sync
     * @param  Medico|null  $medicoDelContexto  el médico que atendió: si la cita del centro no tenía doctor,
     *                                          queda a su nombre (Fase 3 del cambio de `reg_medico`)
     *
     * @throws CitaNoAtendible si la cita no tiene historia y no se la puede resolver
     */
    public function ejecutar(
        Consulta $consulta,
        CarbonInterface $fecha,
        CarbonInterface $cuando,
        int $pacienteId,
        ?int $sede = null,
        string $origen = 'web',
        ?Medico $medicoDelContexto = null,
    ): ConsultaCerrada {
        $cita = $this->citas->de(
            (int) $consulta->numhistoria,
            $fecha,
            (string) $consulta->reg_medico,
            $sede,
            $pacienteId,
        );

        if ($cita === null) {
            return ConsultaCerrada::sinCita();
        }

        if (! $cita->terminable()) {
            return ConsultaCerrada::citaMovida($cita->id);
        }

        $this->atenderCita->ejecutar(Cola::findOrFail($cita->id), true, $cuando, $origen, $medicoDelContexto);

        return ConsultaCerrada::atendida($cita->id);
    }
}
