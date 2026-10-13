<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Agenda\Vistas\FilaDeCitaVista;
use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * La pantalla **"Citas del paciente"** (`/clinica/pacientes/{paciente}/citas`): todas las citas de un
 * paciente con sus dos acciones y el cambio de cédula. Reemplaza a `w_listado_pacientes_a_citas`
 * (el "Cita x Paciente" del escritorio, WEB-2.15).
 *
 * Las filas **no** son otra cosa que `FilaDeCitaVista` —la misma de la agenda—: qué se puede editar,
 * eliminar o cobrar lo decide `CitaDeAgenda` en un solo lugar, y acá solo se le agrega lo que es propio
 * de esta pantalla (la fecha y la hora legibles, el estado en palabras y el texto de la confirmación).
 * Con `posicion` nula, esa fila no muestra turno: no hay jornada a la vista.
 *
 * La **única** acción primaria de la pantalla es el cambio de cédula (el `btn-primary` del encabezado);
 * en la fila, "Modificar" es un `btn-outline-primary` y "Eliminar" un `btn-outline-danger` con la
 * confirmación de `shell.js`. Y cuando una cita no puede eliminarse, la fila **dice por qué** en lugar
 * de ofrecer un botón que va a fallar.
 */
final class CitasDelPacienteVista
{
    /**
     * @param  object  $paciente  fila de `PacientesDelConsultorio::ficha()` (la misma de la ficha)
     * @param  Collection<int,CitaDeAgenda>  $citas  ya ordenadas de la más reciente a la más vieja
     */
    public function __construct(
        private object $paciente,
        private Collection $citas,
    ) {
    }

    public function nombre(): string
    {
        return trim(($this->paciente->apellidos ?? '') . ', ' . ($this->paciente->nombres ?? ''), ', ');
    }

    public function numeroDeHistoria(): ?string
    {
        return $this->paciente->numhistoria !== null ? (string) $this->paciente->numhistoria : null;
    }

    public function cedula(): ?string
    {
        return trim((string) $this->paciente->cedula) ?: null;
    }

    /** Cuántas citas tiene: el subtítulo de la pantalla. */
    public function subtitulo(): string
    {
        $total = $this->citas->count();

        $historia = $this->numeroDeHistoria() !== null
            ? 'Historia N.º ' . $this->numeroDeHistoria()
            : 'Todavía sin número de historia';

        return $total === 0
            ? $historia . '. No tiene citas registradas.'
            : $historia . ' · ' . $total . ($total === 1 ? ' cita' : ' citas') . ', la más reciente primero.';
    }

    public function estaVacio(): bool
    {
        return $this->citas->isEmpty();
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        return [
            'icono'  => 'bi-calendar-x',
            'titulo' => 'Todavía no tiene citas',
            'texto'  => 'Las citas que se le agenden aparecen acá, con su estado y sus acciones.',
        ];
    }

    /** Volver a la agenda, que es la pantalla desde la que se llega. */
    public function urlAgenda(): string
    {
        return route('clinica.agenda', ['vista' => 'dia', 'fecha' => FechaDelConsultorio::hoy()->toDateString(), 'sede' => 'todas']);
    }

    /** El listado de pacientes: de donde se llega cuando la pantalla se abre desde el menú. */
    public function urlPacientes(): string
    {
        return route('clinica.pacientes');
    }

    /** La ficha del paciente, con sus datos y sus consultas. */
    public function urlFicha(): string
    {
        return route('clinica.pacientes.ver', $this->paciente->id);
    }

    /** El formulario de edición de una cita (el mismo alta de la agenda). */
    public function urlEditar(int $cita): string
    {
        return route('clinica.agenda.editar', $cita);
    }

    /**
     * La baja de una cita: la Action compartida de WEB-2.3, que es la que aplica la regla. La ruta es la
     * de esta pantalla y no la de la agenda por una sola razón: la de la agenda vuelve a la agenda, y acá
     * el usuario tiene que volver al listado que estaba mirando.
     */
    public function urlEliminar(int $cita): string
    {
        return route('clinica.pacientes.citas.eliminar', ['paciente' => $this->paciente->id, 'cola' => $cita]);
    }

    // ------------------------------------------------------------------------------ Cambio de cédula

    /**
     * El cambio de cédula ("Cédula actual → modificada" de la fuente) es la **acción primaria** de la
     * pantalla: es lo único que no se puede hacer desde ningún otro lado.
     */
    public function urlCambiarCedula(): string
    {
        return route('clinica.pacientes.cedula', $this->paciente->id);
    }

    /**
     * ¿Se puede cambiar la cédula? Sin `numhistoria` no hay a qué fila de `pacientes` escribirle —el
     * vínculo con el médico existe y la cita también, pero la ficha todavía no tiene número—, así que
     * el botón se muestra deshabilitado con su motivo, no escondido.
     */
    public function puedeCambiarCedula(): bool
    {
        return $this->numeroDeHistoria() !== null;
    }

    public function motivoSinCambiarCedula(): ?string
    {
        return $this->puedeCambiarCedula()
            ? null
            : 'Todavía no tiene número de historia: el cambio de cédula se hace sobre la ficha, y esta se completa cuando el paciente se registra.';
    }

    // ----------------------------------------------------------------------------------------- Filas

    /** @return array<int,FilaDeCitaVista> */
    public function filas(): array
    {
        return $this->citas
            ->map(fn (CitaDeAgenda $cita) => new FilaDeCitaVista($cita, null, false, FechaDelConsultorio::ahora()))
            ->all();
    }

    /**
     * La fecha de la cita, legible: `jueves 17/09/2026`. Es el eje de esta pantalla (en la agenda el eje
     * es la jornada y la fecha está en el título), y por eso la columna va primera.
     */
    public function cuando(FilaDeCitaVista $fila): string
    {
        return FechaClinica::tituloDia($fila->fecha());
    }

    /**
     * El estado de la cita en una palabra, para el listado: atendida, movida, confirmada o por atender.
     *
     * Es el estado de la **cita**, no el de pago: los dos son dimensiones independientes y en esta
     * pantalla el pago va en su propia columna (y con el mismo texto que en la agenda).
     *
     * @return array{tono:string, icono:string, texto:string, titulo:?string}
     */
    public function estado(FilaDeCitaVista $fila): array
    {
        if ($fila->movida()) {
            return ['tono' => 'neutro', 'icono' => 'bi-arrow-right-circle', 'texto' => 'Movida',
                'titulo' => 'El escritorio la pasó a otro día'];
        }

        if ($fila->atendida()) {
            return ['tono' => 'exito', 'icono' => 'bi-check2-all', 'texto' => 'Atendida', 'titulo' => null];
        }

        if ($fila->estaConfirmada()) {
            return ['tono' => 'marca', 'icono' => 'bi-check2-circle', 'texto' => 'Confirmada', 'titulo' => null];
        }

        return ['tono' => 'neutro', 'icono' => 'bi-hourglass-split', 'texto' => 'Por atender', 'titulo' => null];
    }

    /**
     * La confirmación de la fila, con la fecha y la hora **en el texto**: la guía pide que una acción
     * destructiva nombre lo que borra, y acá hay varias citas del mismo paciente (y del mismo día).
     */
    public function confirmarEliminar(FilaDeCitaVista $fila): string
    {
        $cuando = $this->cuando($fila) . ($fila->hora() !== '—' ? ' a las ' . $fila->hora() : '');

        return '¿Eliminar la cita del ' . $cuando . '? No se puede deshacer.';
    }

    /**
     * Por qué la fila no ofrece "Eliminar", cuando no lo ofrece por una regla y no por estar ya
     * atendida (eso se ve en el estado). `EliminarCita` es quien manda; esto es solo el aviso.
     */
    public function motivoSinEliminar(FilaDeCitaVista $fila): ?string
    {
        if ($fila->movida()) {
            return 'El escritorio la pasó a otro día: el paciente ya está en la fecha nueva.';
        }

        if ($fila->atendida()) {
            return 'Ya fue atendida: la consulta queda colgando de la cita.';
        }

        $pago = $fila->estadoPago();

        return in_array($pago, [Cola::PAGO_PAGADA, Cola::PAGO_ABONADA], true)
            ? 'Tiene un pago registrado.'
            : null;
    }
}
