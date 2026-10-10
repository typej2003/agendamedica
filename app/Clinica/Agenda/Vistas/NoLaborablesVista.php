<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Models\DiaNoLaborable;
use App\Models\Medico;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * La pantalla de **días no laborables** (`/clinica/agenda/no-laborables`): el mes, sus días marcados y el
 * formulario para marcar o editar uno. Una sola pantalla para las dos cosas —como el ABM del escritorio—
 * porque el caso es corto: una fecha, un tipo y un motivo.
 */
final class NoLaborablesVista
{
    use ConValoresDeFormulario;

    /** @param  Collection<int,DiaNoLaborable>  $dias  los días marcados del mes */
    public function __construct(
        private Carbon $mes,
        private Collection $dias,
        private ?DiaNoLaborable $editando,
        private Medico $medico,
    ) {
    }

    private function url(Carbon $mes, array $extra = []): string
    {
        return route('clinica.agenda.no-laborables', array_merge(['mes' => $mes->format('Y-m')], $extra));
    }

    public function subtitulo(): string
    {
        return 'Feriados, congresos y ausencias de '
            . trim(($this->medico->prefix ?? '') . ' ' . $this->medico->name . ' ' . $this->medico->lastname)
            . '. La agenda los avisa al agendar.';
    }

    public function titulo(): string
    {
        return ucfirst(FechaClinica::tituloMes($this->mes));
    }

    public function conteo(): string
    {
        $total = $this->dias->count();

        return $total . ($total === 1 ? ' día marcado' : ' días marcados');
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['vista' => 'dia', 'fecha' => Carbon::today()->toDateString()]);
    }

    public function urlMarcar(): string
    {
        return $this->url($this->mes);
    }

    public function urlAnterior(): string
    {
        return $this->url($this->mes->copy()->subMonthNoOverflow());
    }

    public function urlSiguiente(): string
    {
        return $this->url($this->mes->copy()->addMonthNoOverflow());
    }

    public function urlEsteMes(): string
    {
        return $this->url(Carbon::today());
    }

    public function urlGuardar(): string
    {
        return route('clinica.agenda.no-laborables.guardar');
    }

    public function urlCancelar(): string
    {
        return $this->url($this->mes);
    }

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el día marcado' : 'Marcar un día';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar' : 'Marcar';
    }

    /** El día del formulario: el que se edita o, para el alta, hoy (si se mira este mes) o el 1 del mes. */
    public function dia(): string
    {
        return old('dia', $this->editando?->dia?->toDateString()
            ?? ($this->mes->isCurrentMonth() ? Carbon::today()->toDateString() : $this->mes->copy()->startOfMonth()->toDateString()));
    }

    /** @return array<int,array{valor:string, texto:string, elegido:bool}> */
    public function opcionesDeTipo(): array
    {
        $elegido = $this->valor('tipo', DiaNoLaborable::FERIADO);

        return collect(DiaNoLaborable::etiquetas())
            ->map(fn ($texto, $clave) => ['valor' => (string) $clave, 'texto' => $texto, 'elegido' => $elegido === (string) $clave])
            ->values()
            ->all();
    }

    public function estaVacio(): bool
    {
        return $this->dias->isEmpty();
    }

    public function textoVacio(): string
    {
        return 'No hay días marcados en ' . mb_strtolower(FechaClinica::tituloMes($this->mes)) . '.';
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->dias->map(fn (DiaNoLaborable $dia) => [
            'dia'         => FechaClinica::tituloDia($dia->dia),
            'tipo'        => $dia->etiquetaTipo(),
            'motivo'      => $dia->motivo ?: null,
            'urlEditar'   => $this->url($this->mes, ['editar' => $dia->id]),
            'urlEliminar' => route('clinica.agenda.no-laborables.eliminar', $dia->id),
            'confirmar'   => '¿Quitar la marca del ' . $dia->dia->format('d/m/Y') . '?',
        ])->all();
    }
}
