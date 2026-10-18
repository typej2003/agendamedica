<?php

namespace App\Clinica\Agenda;

use App\Clinica\FechaDelConsultorio;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Lo que se está mirando en la agenda: la vista (día / semana / mes), la fecha, la sede y —sin
 * validar todavía contra el catálogo— la razón. Sale de la URL y **nunca revienta** por un valor mal
 * formado: lo que no se entiende vuelve al valor por defecto (día de hoy, sede del contexto).
 */
final class ParametrosDeAgenda
{
    public const VISTAS = ['dia', 'semana', 'mes'];

    public function __construct(
        public readonly string $vista,
        public readonly Carbon $fecha,
        public readonly ?int $sedeId,
        public readonly ?string $razonPedida,
    ) {
    }

    public static function desde(Request $request, array $contexto): self
    {
        $vista = in_array($request->query('vista'), self::VISTAS, true) ? $request->query('vista') : 'dia';
        $razon = $request->query('razon');

        return new self(
            $vista,
            self::fechaDe($request->query('fecha')),
            self::sedeDe($request, $contexto),
            is_string($razon) && trim($razon) !== '' ? trim($razon) : null,
        );
    }

    /** La fecha pedida (`Y-m-d`), o hoy. Nunca revienta por un valor mal formado en la URL. */
    public static function fechaDe(?string $valor): Carbon
    {
        try {
            return $valor ? Carbon::createFromFormat('!Y-m-d', $valor) : FechaDelConsultorio::hoyComoFecha();
        } catch (\Throwable $e) {
            return FechaDelConsultorio::hoyComoFecha();
        }
    }

    /**
     * La sede pedida. Tres casos, y el orden importa:
     *
     *  1. **No viene `sede` en la URL** ⇒ la sede **del consultorio del contexto** (la de la barra
     *     superior). Es lo que hace que «Nueva cita» abra donde el médico está trabajando, venga desde la
     *     agenda, del buscador de pacientes o de «Hacer cita» en los récipes (O-03, 2026-10-17). Si el
     *     contexto está en «todos mis consultorios» no hay ninguna que proponer y queda `null`.
     *  2. **`sede=todas` o `sede=0`** ⇒ `null`: es una elección **explícita** de la agenda (mirar todas las
     *     sedes juntas) y no se pisa con la del contexto. En el formulario de la cita el `null` se muestra
     *     como «— Elige una sede —», porque una cita siempre es en una sede.
     *  3. **`sede=<id>`** ⇒ esa, siempre que sea un id positivo. Un valor que no se entiende vuelve a
     *     `null` (nunca revienta).
     */
    public static function sedeDe(Request $request, array $contexto): ?int
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

    /**
     * La sede con la que abre el **formulario de la cita** («Nueva cita», O-03b del 2026-10-17).
     *
     * Es `sedeDe()` más un solo caso: cuando **no hay sede del contexto** (la barra en «Todos mis
     * consultorios») y **la URL no pidió `sede`**, si el médico tiene **exactamente una** sede vigente se
     * propone esa. Con varias —o con ninguna— se sigue pidiendo elegir, porque la sede decide la jornada y
     * el lugar.
     *
     * `sede=todas` es una elección **explícita** (la agenda mirando todas las sedes juntas) y **no se
     * pisa**: eso ya lo resuelve `sedeDe()` devolviendo `null` con la query presente, y por eso el default
     * sólo entra cuando la URL no trae `sede`. Tampoco se toca la agenda: su filtro por sede sigue siendo
     * el de `sedeDe()`, que en «todos mis consultorios» no filtra (una lista de citas con y sin sede).
     *
     * Las sedes salen de `$contexto['sedes']` —las **vigentes** del médico, la misma lectura con la que
     * `ContextoTrabajo` arma la barra y con la que `ArmadorDeAgenda::sedes()` arma el formulario—: el
     * criterio vive acá y en un solo lugar, no en el Blade ni en el controlador.
     */
    public static function sedeDelFormulario(Request $request, array $contexto): ?int
    {
        $sede = self::sedeDe($request, $contexto);

        if ($sede !== null || $request->query('sede') !== null) {
            return $sede;
        }

        return self::laUnicaSedeDe($contexto);
    }

    /** La sede del médico si tiene **exactamente una** vigente; `null` con varias o con ninguna. */
    private static function laUnicaSedeDe(array $contexto): ?int
    {
        $sedes = collect($contexto['sedes'] ?? []);

        return $sedes->count() === 1 ? (int) $sedes->first()->id : null;
    }

    /** @return array{0: Carbon, 1: Carbon} El primer y el último día que cubre la vista. */
    public function rango(): array
    {
        return match ($this->vista) {
            'semana' => [$this->fecha->copy()->startOfWeek(Carbon::MONDAY), $this->fecha->copy()->endOfWeek(Carbon::SUNDAY)],
            'mes'    => [$this->fecha->copy()->startOfMonth(), $this->fecha->copy()->endOfMonth()],
            default  => [$this->fecha->copy(), $this->fecha->copy()],
        };
    }
}
