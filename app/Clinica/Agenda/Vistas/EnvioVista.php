<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Models\Office;
use App\Notificaciones\Canal;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * La ventana de **envío del día** (WEB-2.7), como `w_sms_enviar` / `w_correo_enviar` del escritorio: los
 * pacientes de la jornada, el canal y el texto. Ahí el usuario tilda a quién le manda.
 */
final class EnvioVista
{
    /**
     * @param  Collection<int,CitaDeAgenda>  $citas  las que se pueden recordar (no confirmadas ni atendidas)
     * @param  array<int,string>  $mensajes  id de cita => texto del recordatorio
     */
    public function __construct(
        private Carbon $fecha,
        private ?Office $sede,
        private Collection $citas,
        private array $mensajes,
        private string $canal,
    ) {
    }

    public function titulo(): string
    {
        return ucfirst(FechaClinica::tituloDia($this->fecha));
    }

    /** "San José" o "Todas las sedes". */
    public function sede(): string
    {
        return $this->sede?->medicalCenter->name ?? 'Todas las sedes';
    }

    public function total(): string
    {
        $total = $this->citas->count();

        return $total . ($total === 1 ? ' paciente' : ' pacientes');
    }

    public function estaVacio(): bool
    {
        return $this->citas->isEmpty();
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->fecha->toDateString(), 'sede' => $this->sede?->id ?? 'todas']);
    }

    public function accion(): string
    {
        return route('clinica.agenda.envio.enviar');
    }

    /** Lo que el POST necesita para volver a esta misma pantalla. */
    public function ocultos(): array
    {
        return ['fecha' => $this->fecha->toDateString(), 'sede' => $this->sede?->id ?? 'todas'];
    }

    /** @return array<int,array{clave:string, etiqueta:string, elegido:bool}> */
    public function canales(): array
    {
        $canales = [];

        foreach (Canal::etiquetas() as $clave => $etiqueta) {
            $canales[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'elegido' => $this->canal === $clave];
        }

        return $canales;
    }

    /**
     * El texto arranca con el del primer paciente (el de la plantilla de su médico): en la mayoría de los
     * días todos los de la jornada son del mismo médico.
     */
    public function mensajePorDefecto(): string
    {
        return $this->mensajes === [] ? "" : (string) array_values($this->mensajes)[0];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->citas->map(fn (CitaDeAgenda $cita) => [
            'id'            => $cita->id,
            'hora'          => $cita->horaCorta(),
            'paciente'      => $cita->paciente,
            'sinHistoria'   => ! $cita->numHistoria,
            'telefono'      => $cita->telefono ?: null,
            'correo'        => $cita->email ?: null,
            'tieneTelefono' => $cita->tieneTelefono(),
            'tieneCorreo'   => $cita->tieneCorreo(),
        ])->all();
    }
}
