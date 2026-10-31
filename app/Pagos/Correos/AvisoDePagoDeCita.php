<?php

namespace App\Pagos\Correos;

use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Pacientes\DuenoDeLaHistoria;
use App\Mail\PagoDeCitaAlMedico;
use App\Mail\PagoDeCitaAlPaciente;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Notificaciones\Correo;
use App\Paciente\CitaDelPaciente;
use App\Paciente\PuedeGestionarSuCita;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Los **dos avisos por correo de una cita pagada**: al médico (le pagaron una cita) y al paciente (su pago
 * se recibió y la cita quedó agendada).
 *
 * Se llama desde `Cobros::aprobar` **después** de la transacción, igual que el comprobante del plan: si el
 * SMTP está caído, el pago y la cita ya quedaron como estaban. Cada destinatario se intenta por separado,
 * así que un fallo con uno no impide el otro, y lo que no se pudo mandar queda en `pagos_eventos`.
 *
 * Los datos de la cita salen de `CitaDelPaciente`, la misma clase con la que «Mis citas» muestra al
 * médico, la sede, la dirección, el día y la hora. No se lee de nuevo la cita ni se duplica esa lógica.
 */
final class AvisoDePagoDeCita
{
    public function __construct(private readonly ArmadorDeAgenda $armador)
    {
    }

    public function enviar(Pago $pago): void
    {
        try {
            $cita = $pago->cita();

            if (! $cita) {
                $this->omitir($pago, 'ambos', 'sin_cita');

                return;
            }

            $medico = $this->medicoDe($cita);

            if (! $medico) {
                // Sin el médico no se pueden armar los datos de la cita (médico y especialidad).
                $this->omitir($pago, 'ambos', 'sin_medico');

                return;
            }

            $paciente = $this->pacienteDe($cita);
            $vista = $this->vistaDe($cita, $medico);
            $datos = DatosDeLaCitaPagada::desde($pago, $cita, $vista, $paciente);

            $this->enviarAlMedico($pago, $medico, $datos);
            $this->enviarAlPaciente($pago, $paciente, $datos);
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_error', [
                'destinatario' => 'ambos',
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    /**
     * El médico del consultorio de la cita. Es el mismo camino que el comprobante del plan
     * (`Cobros::enviarComprobante`); si la cita tiene un doctor asignado y el consultorio no tiene una fila
     * propia, se usa ese, para no quedarse sin aviso.
     */
    private function medicoDe(Cola $cita): ?Medico
    {
        $medico = Medico::where('reg_medico', $cita->reg_medico)->first();

        if ($medico === null && $cita->medico_id !== null) {
            $medico = Medico::find($cita->medico_id);
        }

        return $medico;
    }

    /**
     * La ficha del paciente de la cita.
     *
     * `pacientes` **no tiene** columna `numhistoria` (el número vive en `historias` y en el pivote
     * `medico_pacientes`), así que no se puede buscar la ficha con un `where('numhistoria')`. La regla
     * única para saber de quién es un número de historia es `DuenoDeLaHistoria`, que mira las dos tablas
     * y desambigua por `reg_medico`/`medico_id`; es la misma que usa la agenda.
     */
    private function pacienteDe(Cola $cita): ?Paciente
    {
        if ($cita->numhistoria === null || trim((string) $cita->numhistoria) === '') {
            return $cita->paciente_sinhistoria_id !== null
                ? Paciente::find($cita->paciente_sinhistoria_id)
                : null;
        }

        $pacienteId = app(DuenoDeLaHistoria::class)->de($cita->reg_medico, $cita->numhistoria);

        return $pacienteId !== null ? Paciente::find($pacienteId) : null;
    }

    /**
     * La cita lista para mostrar, con la sede y la dirección ya resueltas por `ArmadorDeAgenda` (las citas
     * legadas no guardan la sede: sale de la historia del paciente).
     */
    private function vistaDe(Cola $cita, Medico $medico): CitaDelPaciente
    {
        $fila = $this->armador->desdeFilas($cita->reg_medico ?? '', collect([$cita]))->first();

        if ($fila === null) {
            throw new \RuntimeException('No se pudo armar la cita para el correo.');
        }

        return new CitaDelPaciente(
            $fila,
            $medico,
            MedicalCenter::find($fila->centroId),
            PuedeGestionarSuCita::aviso($fila),
        );
    }

    private function enviarAlMedico(Pago $pago, Medico $medico, DatosDeLaCitaPagada $datos): void
    {
        $email = Correo::valido($medico->email);

        if ($email === null) {
            $this->omitir($pago, 'medico', 'sin_correo');

            return;
        }

        try {
            Mail::to($email)->send(new PagoDeCitaAlMedico($pago, $datos));
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_error', [
                'destinatario' => 'medico',
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    private function enviarAlPaciente(Pago $pago, ?Paciente $paciente, DatosDeLaCitaPagada $datos): void
    {
        $email = Correo::valido($paciente?->email);

        if ($email === null) {
            $this->omitir($pago, 'paciente', 'sin_correo');

            return;
        }

        try {
            Mail::to($email)->send(new PagoDeCitaAlPaciente($pago, $datos));
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_error', [
                'destinatario' => 'paciente',
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    private function omitir(Pago $pago, string $destinatario, string $motivo): void
    {
        $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_omitido', [
            'destinatario' => $destinatario,
            'motivo' => $motivo,
        ]);
    }
}
