<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\Jornada;
use Carbon\Carbon;

/**
 * Una jornada (una sede en un día) tal como se ve en su tarjeta: la sede, la modalidad, el horario, el
 * cupo y las citas. La jornada y su cupo los resuelve `Jornada` (portado del móvil); acá se arma el texto.
 */
final class JornadaVista
{
    /**
     * @param  array<int,int>  $posiciones  id de cita => posición calculada sobre todo el rango
     * @param  array<int,string>  $mensajes  id de cita => texto del recordatorio
     * @param  bool  $puedeAbrirLaConsulta  si el usuario puede entrar a la consulta (WEB-4.1: sólo el
     *                                      médico). Lo resuelve el controlador, no la vista.
     */
    public function __construct(
        private Jornada $jornada,
        private array $posiciones,
        private array $mensajes,
        private Carbon $ahora,
        private bool $conArrastre,
        private bool $puedeAbrirLaConsulta = true,
        /** @var array<int,int>  ids de cita (ya atendida) cuya consulta tiene récipes para imprimir (WEB-5.2) */
        private array $citasConRecipes = [],
    ) {
    }

    public function fecha(): string
    {
        return $this->jornada->fecha;
    }

    public function sede(): string
    {
        return $this->jornada->nombreSede();
    }

    /** @return array{tono:string, icono:string, texto:string} */
    public function modalidad(): array
    {
        return $this->jornada->esPorOrdenDeLlegada()
            ? ['tono' => 'info', 'icono' => 'bi-people', 'texto' => 'Orden de llegada']
            : ['tono' => 'neutro', 'icono' => 'bi-clock', 'texto' => 'Hora de cita'];
    }

    /** "08:00 a 12:00 · turno mañana". */
    public function horario(): string
    {
        return $this->jornada->horarioTexto() . ' · turno ' . $this->jornada->turno();
    }

    public function cantidad(): int
    {
        return $this->jornada->cantidad();
    }

    public function cantidadTexto(): string
    {
        $cantidad = $this->jornada->cantidad();

        return $cantidad . ($cantidad === 1 ? ' paciente' : ' pacientes');
    }

    public function tieneCupo(): bool
    {
        return $this->jornada->cupo() !== null;
    }

    /** "cupo 2/5", o "sin cupo configurado". */
    public function cupoTexto(): string
    {
        $cupo = $this->jornada->cupo();

        return $cupo === null ? 'sin cupo configurado' : 'cupo ' . $this->jornada->cantidad() . '/' . $cupo;
    }

    /** El cupo usado en porcentaje (tope 100) para la barra; `null` si no hay cupo configurado. */
    public function cupoPorcentaje(): ?int
    {
        $cupo = $this->jornada->cupo();

        return $cupo ? min(100, (int) round($this->jornada->cantidad() * 100 / $cupo)) : null;
    }

    /** El cupo avisa, no bloquea: con la jornada llena se sigue pudiendo agendar. */
    public function llena(): bool
    {
        return $this->jornada->llena();
    }

    public function proximo(): string
    {
        return '#' . $this->jornada->siguienteNumero();
    }

    /** "1 movida por el escritorio", o `null` si no hay. */
    public function movidasTexto(): ?string
    {
        $movidas = $this->jornada->movidas()->count();

        return $movidas === 0 ? null : $movidas . ($movidas === 1 ? ' movida' : ' movidas') . ' por el escritorio';
    }

    public function urlAgendar(): string
    {
        return route('clinica.agenda.nueva', ['fecha' => $this->jornada->fecha, 'sede' => $this->jornada->sede?->id]);
    }

    /**
     * Se arrastra solo donde el orden lo decide la cola (sede por orden de llegada) y de hoy en adelante:
     * en una sede con hora de cita la posición la fija la hora, y reordenar un día pasado no significa
     * nada. Es la misma restricción que el móvil.
     */
    public function puedeReordenar(): bool
    {
        return $this->conArrastre
            && $this->jornada->esPorOrdenDeLlegada()
            && $this->jornada->fecha >= $this->ahora->toDateString();
    }

    public function urlReordenar(): string
    {
        return route('clinica.agenda.reordenar');
    }

    /** @return array<int,FilaDeCitaVista> */
    public function filas(): array
    {
        $arrastrable = $this->puedeReordenar();

        return $this->jornada->ordenadas()
            ->map(fn (array $fila) => new FilaDeCitaVista(
                $fila['cita'],
                $this->posiciones[$fila['cita']->id] ?? null,
                $arrastrable,
                $this->ahora,
                $this->mensajes[$fila['cita']->id] ?? '',
                $this->puedeAbrirLaConsulta,
                isset($this->citasConRecipes[$fila['cita']->id]),
            ))
            ->all();
    }
}
