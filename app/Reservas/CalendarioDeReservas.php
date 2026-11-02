<?php

namespace App\Reservas;

use App\Clinica\FechaDelConsultorio;
use Carbon\Carbon;

/**
 * **El calendario del portal de reservas** (O-52): qué mes se está mirando, qué días tienen lugar y cuál
 * está elegido.
 *
 * Es la parte sin estado del calendario: recibe el mapa `fecha => opciones` que resolvió
 * `DisponibilidadPublica::agendaDelRango()` y lo convierte en la rejilla que pinta la vista. No consulta la
 * base ni decide la disponibilidad.
 *
 * ## Por qué sólo el mes actual y el siguiente
 *
 * El portal resuelve la agenda **día por día**, y cada día cuesta lo mismo que el cálculo del chatbot. Dejar
 * que el paciente navegue a un año vista multiplicaría ese costo por doce. Con dos meses alcanza para
 * «esta semana o la que viene», que es como se reserva una cita médica, y mantiene el portal rápido.
 *
 * ## Sin JavaScript
 *
 * La rejilla son enlaces (`?fecha=…` y `?mes=…`): se usa con teclado, con una mano y sin que el navegador
 * ejecute nada. Los días sin lugar no son enlaces, así que el lector de pantalla los anuncia como texto.
 */
final class CalendarioDeReservas
{
    /** Cuántos meses hacia adelante se puede navegar (el actual y el siguiente). */
    public const MESES_HACIA_ADELANTE = 1;

    /**
     * El mes pedido, normalizado al primer día. Un valor inválido, del pasado o demasiado lejano cae en el
     * mes actual: la URL no puede llevar el calendario a cualquier lado.
     */
    public function mes(?string $pedido): Carbon
    {
        $hoy = FechaDelConsultorio::hoyComoFecha()->startOfMonth();
        $limite = $hoy->copy()->addMonths(self::MESES_HACIA_ADELANTE);

        $pedido = trim((string) $pedido);

        if (! preg_match('/^\d{4}-\d{2}$/', $pedido)) {
            return $hoy;
        }

        $mes = Carbon::createFromFormat('!Y-m', $pedido)->startOfMonth();

        return $mes->lessThan($hoy) || $mes->greaterThan($limite) ? $hoy : $mes;
    }

    /** El primer día que se consulta del mes: hoy si el mes es el actual, si no el día 1. */
    public function primerDiaVisible(Carbon $mes): Carbon
    {
        $hoy = FechaDelConsultorio::hoyComoFecha();

        return $mes->isSameMonth($hoy) ? $hoy->copy() : $mes->copy();
    }

    /** Cuántos días hay que pedir para cubrir el mes desde el primer día visible. */
    public function diasDelMes(Carbon $mes): int
    {
        $desde = $this->primerDiaVisible($mes);

        return (int) $desde->diffInDays($mes->copy()->endOfMonth()) + 1;
    }

    /**
     * La fecha elegida, si de verdad tiene lugar en la agenda calculada; si no, `null` (y la ficha vuelve a
     * mostrar los próximos horarios).
     *
     * @param  array<string,array<int,OpcionDeReserva>>  $agenda
     */
    public function fechaElegida(?string $fecha, array $agenda): ?string
    {
        $fecha = trim((string) $fecha);

        return $fecha !== '' && array_key_exists($fecha, $agenda) ? $fecha : null;
    }

    /**
     * La rejilla del mes: los días con lugar, el elegido y los enlaces de navegación.
     *
     * @param  array<string,array<int,OpcionDeReserva>>  $agenda
     * @return array{titulo:string, anterior:?string, siguiente:?string, dias:array<int,array{fecha:string, numero:int, disponible:bool, elegido:bool, hoy:bool}|null>}
     */
    public function rejilla(Carbon $mes, array $agenda, ?string $fecha = null): array
    {
        $hoy = FechaDelConsultorio::hoyComoFecha();
        $inicio = $mes->copy()->startOfMonth();
        $fin = $mes->copy()->endOfMonth();

        // Semana que empieza el lunes: los huecos de antes del día 1 y de después del último.
        $huecos = $inicio->dayOfWeekIso - 1;
        $dias = array_fill(0, $huecos, null);

        for ($dia = $inicio->copy(); $dia->lessThanOrEqualTo($fin); $dia->addDay()) {
            $clave = $dia->toDateString();

            $dias[] = [
                'fecha' => $clave,
                'numero' => (int) $dia->day,
                'disponible' => array_key_exists($clave, $agenda),
                'elegido' => $clave === $fecha,
                'hoy' => $clave === $hoy->toDateString(),
            ];
        }

        while (count($dias) % 7 !== 0) {
            $dias[] = null;
        }

        return [
            'titulo' => ucfirst($mes->copy()->locale('es')->isoFormat('MMMM [de] YYYY')),
            'anterior' => $this->mesVecino($mes, -1),
            'siguiente' => $this->mesVecino($mes, 1),
            'dias' => $dias,
        ];
    }

    /** El mes vecino si está dentro de lo permitido; `null` si no (la vista no dibuja el enlace). */
    private function mesVecino(Carbon $mes, int $salto): ?string
    {
        $hoy = FechaDelConsultorio::hoyComoFecha()->startOfMonth();
        $vecino = $mes->copy()->addMonths($salto);
        $limite = $hoy->copy()->addMonths(self::MESES_HACIA_ADELANTE);

        return $vecino->lessThan($hoy) || $vecino->greaterThan($limite) ? null : $vecino->format('Y-m');
    }
}
