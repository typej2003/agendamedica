<?php

namespace App\Clinica\Listados;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * **Un rango de fechas cerrado por los dos lados** (`desde` y `hasta`, los dos incluidos) y la cuenta que el
 * listado de embarazos hace con él.
 *
 * Existe por una regla del legado que hay que dejar escrita y en un solo lugar: el *Listado de Partos* del
 * escritorio pide dos fechas y **no** las compara contra la FUR. Las corre **283 días para atrás** y recién
 * ahí filtra:
 *
 * ```
 * FUR_1 = relativedate( primero , -283 )      // w_reportes_consultas.srw:1312
 * FUR_2 = relativedate( segundo , -283 )      // w_reportes_consultas.srw:1313
 * ...
 * WHERE pre_natal_observaciones.fur between :f_ini and :f_fin    // d_partos.srd
 * ```
 *
 * O sea: el rango se aplica a la **FUR desplazada**, que es lo mismo que pedir las gestas cuya **FPP**
 * (FUR + 283) cae dentro del rango elegido. Los 283 son los de los **reportes** —el mismo número que
 * documenta la wiki y que usan los ecos; el control prenatal vivo usa 281, ver `EdadGestacional`—, así que
 * el número lo pone quien llama (`FiltroDeEmbarazos`) y acá vive sólo la cuenta.
 *
 * Las fechas se normalizan a **medianoche sin zona** (`createFromFormat('!Y-m-d', …)`), como
 * `FechaDelConsultorio::hoyComoFecha()`: comparar un día del consultorio con un día pedido a mano no puede
 * depender de la hora a la que se mira la pantalla.
 */
final class RangoDeFechas
{
    private function __construct(
        private CarbonInterface $desde,
        private CarbonInterface $hasta,
        private bool $seVolteo = false,
    ) {
    }

    /**
     * El rango pedido, con los valores por defecto del legado y el orden corregido.
     *
     * Los defaults son los del escritorio: su campo "desde" nace en **01/01/2000** y el "hasta" en **hoy**
     * (`w_reportes_consultas.srw`, `em_3.text`/`em_4.text`). "Hoy" no se resuelve acá —el día es del
     * consultorio, no del servidor—, así que llega por parámetro.
     *
     * Un rango al revés **no se rechaza**: se da vuelta y `seVolteo()` dice que se hizo, para que la pantalla
     * lo avise en vez de dejar la tabla vacía sin explicación (el legado cortaba con un `Messagebox`, que en
     * una pantalla web es un callejón: la guía de UI pide decir qué pasó, no bloquear).
     */
    public static function de(?string $desde, ?string $hasta, CarbonInterface $hoy): self
    {
        $primero = self::fecha($desde) ?? self::PRIMERA_FECHA_DEL_LEGADO();
        $segundo = self::fecha($hasta) ?? self::dia($hoy);

        if ($primero->greaterThan($segundo)) {
            return new self($segundo, $primero, true);
        }

        return new self($primero, $segundo);
    }

    /** El extremo inicial, `AAAA-MM-DD`. */
    public function desde(): string
    {
        return $this->desde->toDateString();
    }

    /** El extremo final, incluido, `AAAA-MM-DD`. */
    public function hasta(): string
    {
        return $this->hasta->toDateString();
    }

    /** ¿Las fechas venían al revés y hubo que darlas vuelta? La pantalla lo avisa. */
    public function seVolteo(): bool
    {
        return $this->seVolteo;
    }

    /**
     * **El rango de FUR que corresponde a este rango de FPP**: el mismo rango corrido `$dias` para atrás.
     *
     * Es la traducción de las dos líneas del legado (`w_reportes_consultas.srw:1312-1313`): pedir las gestas
     * cuya FPP cae entre `desde` y `hasta` es exactamente pedir `fur between (desde − $dias) and (hasta − $dias)`.
     */
    public function furDeLaFpp(int $dias): self
    {
        return new self(
            $this->desde->copy()->subDays($dias),
            $this->hasta->copy()->subDays($dias),
            $this->seVolteo,
        );
    }

    /**
     * El rango de **fechas de nacimiento** que corresponde a las edades pedidas (las dos incluidas).
     *
     * El legado filtra con `(today - pacientes.fnacimiento)/365 between :edad_1 and :edad_2`
     * (`d_partos.srd`): una división entera de los días vividos. La web usa la misma cuenta de edad que el
     * resto de las pantallas (`FechaClinica::edad()`, los años cumplidos), y para que el filtro y la columna
     * "Edad" digan lo mismo se traduce la edad a fechas de nacimiento en vez de repetir la división:
     *
     * ```
     * edad ≥ inicial  ⇔  fnacimiento ≤ hoy − inicial años
     * edad ≤ final    ⇔  fnacimiento >  hoy − (final + 1) años    (⇔ ≥ ese día + 1)
     * ```
     *
     * Un paciente sin fecha de nacimiento no entra en ninguna edad, que es lo mismo que hacía el `NULL` del
     * legado (un `between` con `NULL` no es verdadero).
     */
    public static function nacimientosDeLasEdades(CarbonInterface $hoy, int $edadInicial, int $edadFinal): self
    {
        $hoy = self::dia($hoy);

        return new self(
            $hoy->copy()->subYears($edadFinal + 1)->addDay(),
            $hoy->copy()->subYears($edadInicial),
        );
    }

    /**
     * **El rango de FUR de las gestas de una semana exacta**: la ventana de siete días que hoy da esa edad
     * gestacional.
     *
     * El legado filtra `(today - fur)/7 = :semana` (`d_partos_semana.srd`), una división entera: la semana
     * `N` son los días vividos desde la FUR entre `7N` y `7N + 6` —la misma cuenta de `EdadGestacional`, que
     * es `int(días / 7)`—. Escrito como fechas: la FUR tiene que caer entre `hoy − (7N + 6)` y `hoy − 7N`.
     */
    public static function furDeLaSemanaDeGestacion(CarbonInterface $hoy, int $semana): self
    {
        $hoy = self::dia($hoy);

        return new self(
            $hoy->copy()->subDays(7 * $semana + 6),
            $hoy->copy()->subDays(7 * $semana),
        );
    }

    /** La primera fecha con la que nace el reporte del escritorio. */
    private static function PRIMERA_FECHA_DEL_LEGADO(): CarbonInterface
    {
        return Carbon::createFromFormat('!Y-m-d', '2000-01-01');
    }

    /** Un día del consultorio, a medianoche y sin zona, para poder compararlo con una fecha pedida a mano. */
    private static function dia(CarbonInterface $fecha): CarbonInterface
    {
        return Carbon::createFromFormat('!Y-m-d', $fecha->toDateString());
    }

    /** Una fecha del legado (`AAAA-MM-DD`, o `DD/MM/AAAA` como la escribe su campo), o `null` si no sirve. */
    private static function fecha(?string $valor): ?CarbonInterface
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, $texto);
            } catch (Throwable $e) {
                // Se prueba con el otro formato; si ninguno sirve, la fecha no existe.
            }
        }

        return null;
    }
}
