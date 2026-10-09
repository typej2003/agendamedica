<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\ReordenarCola;
use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Agenda\Jornada;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Agenda y secretaría de la web clínica (PLAN-WEB.md, F2): la vista **día / semana / mes por sede**,
 * con la modalidad y el cupo de cada jornada, y las acciones del mostrador —confirmar, atender,
 * cobrar y reordenar— sobre las **Actions compartidas** con el sync del móvil.
 *
 * Nada de la lectura se recalcula en la vista: la jornada, la posición, la modalidad y el cupo los
 * resuelve `ArmadorDeAgenda` (portado del móvil); acá solo se decide qué rango y qué sede se mira.
 */
class AgendaController extends Controller
{
    private const VISTAS = ['dia', 'semana', 'mes'];

    public function __construct(private ArmadorDeAgenda $armador)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];

        $vista = in_array($request->query('vista'), self::VISTAS, true) ? $request->query('vista') : 'dia';
        $fecha = $this->fecha($request->query('fecha'));
        [$desde, $hasta] = $this->rango($vista, $fecha);

        $sedes = $this->armador->sedes($regMedico, $contexto['medico']->id);
        $sedeId = $this->sedePedida($request, $contexto);
        $centroId = $sedeId ? $sedes->firstWhere('id', $sedeId)?->medical_center_id : null;

        // La posición del paciente se calcula sobre **todas** las citas del rango, no sobre las que
        // quedan visibles al filtrar: si la secretaria filtra, el paciente 7 sigue siendo el 7.
        $delRango = $this->armador->citas($regMedico, $desde, $hasta);
        $posiciones = $this->armador->posiciones($this->armador->jornadas($delRango, $sedes));

        $visibles = $centroId === null
            ? $delRango
            : $delRango->where('centroId', $centroId)->values();

        $conteos = $visibles->groupBy('fecha')->map->count();

        return view('clinica.agenda.index', [
            'vista'         => $vista,
            'fecha'         => $fecha,
            'sedeId'        => $sedeId,
            'sedes'         => $sedes,
            'jornadas'      => $this->armador->jornadas($visibles, $sedes),
            'posiciones'    => $posiciones,
            'conteosPorDia' => $conteos,
            'semana'        => $this->diasDeLaSemana($fecha),
            'semanasDelMes' => $vista === 'mes' ? $this->semanasDelMes($fecha, $conteos) : [],
            'totalRango'    => $visibles->count(),
            'ahora'         => Carbon::now(),
        ]);
    }

    /** Confirmar la cita (o quitarle la confirmación, mientras no esté atendida). */
    public function confirmar(Request $request, Cola $cola, ConfirmarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);
        $estado = (int) $request->input('estado', Cola::ESTADO_CONFIRMADA);

        if ($estado === Cola::ESTADO_NO_CONFIRMADA && (int) $cita->atendido === 1) {
            return back()->with('error', 'Una cita ya atendida no se puede quedar sin confirmar.');
        }

        $accion->ejecutar($cita, $estado, null, 'web');

        return back()->with(
            'estado',
            $estado === Cola::ESTADO_NO_CONFIRMADA ? 'Se quitó la confirmación.' : 'Cita confirmada.'
        );
    }

    /**
     * Marcar la cita como atendida. **Atender implica confirmado** (lo resuelve la Action).
     *
     * Abrir la consulta es otra cosa: llega con el módulo Consulta (F4), que es donde existe la
     * pantalla. Hasta entonces "Atender" deja la marca, que es el dato que la consulta va a usar.
     */
    public function atender(Request $request, Cola $cola, AtenderCita $accion)
    {
        $accion->ejecutar($this->citaDelContexto($request, $cola), true, null, 'web');

        return back()->with('estado', 'Cita marcada como atendida.');
    }

    /** Cobrar: abono parcial, sumado a lo ya pagado (ver `CobrarCita`). */
    public function cobrar(Request $request, Cola $cola, CobrarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $datos = $request->validate([
            'recibido' => ['required', 'numeric', 'min:0'],
            'monto'    => ['nullable', 'numeric', 'min:0'],
        ]);

        $accion->recibir(
            $cita,
            (float) $datos['recibido'],
            isset($datos['monto']) ? (float) $datos['monto'] : null,
            null,
            'web'
        );

        return back()->with('estado', 'Cobro registrado.');
    }

    /**
     * Reordenar la cola: **una** operación de sync, no N updates (el arrastre manda el movimiento).
     * Responde JSON al arrastre y redirige si el formulario no trae JS.
     */
    public function reordenar(Request $request, ReordenarCola $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'record_id' => ['required', 'integer'],
            'from'      => ['required', 'integer'],
            'to'        => ['required', 'integer'],
            'fecha'     => ['required', 'date'],
        ]);

        $cita = Cola::where('id', $datos['record_id'])->firstOrFail();
        $this->citaDelContexto($request, $cita);

        $accion->ejecutar(
            $cita,
            (int) $datos['from'],
            (int) $datos['to'],
            (string) $datos['fecha'],
            [$contexto['reg_medico']],
            null,
            'web'
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('estado', 'Orden actualizado.');
    }

    /** Una cita del `reg_medico` del contexto; la de otro médico no se toca ni se confirma que exista. */
    private function citaDelContexto(Request $request, Cola $cola): Cola
    {
        $contexto = $request->attributes->get('contexto_clinico');
        abort_unless($cola->reg_medico === $contexto['reg_medico'], 404);

        return $cola;
    }

    /** La fecha pedida (`Y-m-d`), o hoy. Nunca revienta por un valor mal formado en la URL. */
    private function fecha(?string $valor): Carbon
    {
        try {
            return $valor ? Carbon::createFromFormat('!Y-m-d', $valor) : Carbon::today();
        } catch (\Throwable $e) {
            return Carbon::today();
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(string $vista, Carbon $fecha): array
    {
        return match ($vista) {
            'semana' => [$fecha->copy()->startOfWeek(Carbon::MONDAY), $fecha->copy()->endOfWeek(Carbon::SUNDAY)],
            'mes'    => [$fecha->copy()->startOfMonth(), $fecha->copy()->endOfMonth()],
            default  => [$fecha->copy(), $fecha->copy()],
        };
    }

    /** La sede pedida: por defecto la del contexto; `todas` (o 0) muestra todas. */
    private function sedePedida(Request $request, array $contexto): ?int
    {
        $pedida = $request->query('sede');

        if ($pedida === null) {
            return $contexto['office']->id ?? null;
        }

        if ($pedida === 'todas' || $pedida === '0') {
            return null;
        }

        $id = (int) $pedida;

        return $id > 0 ? $id : null;
    }

    /** @return Collection<int,Carbon> Los 7 días de la semana de [fecha], de lunes a domingo. */
    private function diasDeLaSemana(Carbon $fecha): Collection
    {
        $lunes = $fecha->copy()->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 6))->map(fn (int $i) => $lunes->copy()->addDays($i));
    }

    /**
     * El mes como semanas de 7 días, cada día con su conteo (0 si no hay citas). Incluye los días
     * de los meses vecinos que completan la primera y la última semana, marcados como `enMes = false`.
     *
     * @param  Collection<string,int>  $conteos
     * @return array<int,array<int,array{fecha: Carbon, enMes: bool, conteo: int}>>
     */
    private function semanasDelMes(Carbon $fecha, Collection $conteos): array
    {
        $cursor = $fecha->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $fin = $fecha->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $mes = $fecha->month;

        $semanas = [];
        $semana = [];

        while ($cursor->lessThanOrEqualTo($fin)) {
            $semana[] = [
                'fecha'  => $cursor->copy(),
                'enMes'  => $cursor->month === $mes,
                'conteo' => (int) ($conteos[$cursor->toDateString()] ?? 0),
            ];

            if (count($semana) === 7) {
                $semanas[] = $semana;
                $semana = [];
            }

            $cursor->addDay();
        }

        return $semanas;
    }
}
