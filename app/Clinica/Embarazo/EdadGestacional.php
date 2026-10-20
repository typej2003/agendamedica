<?php

namespace App\Clinica\Embarazo;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **La edad gestacional y la FPP de un embarazo** (WEB-6.6, el control prenatal): las dos cuentas que el
 * escritorio hace a partir de la **FUR** y que hasta ahora la web no tenía.
 *
 * Las fórmulas están copiadas de la fuente, no inventadas. La **edad gestacional** es la misma en todas las
 * pantallas de F6:
 *
 * ```
 * int(daysafter(fur, today()) / 7) + ' Semanas y ' + mod(daysafter(fur, today()), 7) + ' días'
 * ```
 *
 * `d_pre_natal_observa.srd:21` (el control prenatal) y `d_eco_obstetrico.srd:36` (el eco obstétrico), o sea:
 * **semanas** = `int(días desde la FUR / 7)` y **días** = `mod(días desde la FUR, 7)`.
 *
 * La **FPP**, en cambio, **cambia según la pantalla** —es una discrepancia del legado, no un error de
 * lectura, y tiene **tres** valores, no dos—: el **control prenatal suma 281**
 * (`d_pre_natal_observa_2.srd:38`, el DataWindow **vivo** de la pestaña *Prenatal*), los **reportes del
 * control y todos los ecos suman 283** (`d_pre_natal_observa_reporte.srd:45`,
 * `d_eco_obstetrico_trimestral_nueva.srd:183`) y hay un **270** que vive en `d_pre_natal_observa.srd:20` y
 * `d_pre_natal_observac.srd:16`, dos DataWindows que **ninguna ventana usa** (el primero está huérfano y el
 * segundo es la solapa vieja y oculta). **La decisión de WEB-6.6 (2026-10-13) es conservar el número de
 * cada pantalla** —el control prenatal 281 y el eco obstétrico 283— porque es lo que el médico viene leyendo
 * en cada pantalla del escritorio y unificarlos cambiaría el dato bajo sus pies; la fórmula de la EG sí es
 * una sola. La web calcula y muestra las dos cosas (no las inventa: no hay columna de FPP y la de EG
 * —`eg_fur`/`eg_eco` de `pre_natal_desarrollo_fino`— la teclea el médico). El **IMC** (peso / talla²,
 *
 * **No se guarda en ninguna columna**: en el legado la FPP es un `compute` del DataWindow y la edad
 * gestacional se muestra calculada; las columnas `eg_fur`/`eg_eco` de `pre_natal_desarrollo` las escribe el
 * escritorio en sus controles. Acá se calcula y se muestra, igual que el IMC de la evaluación física.
 *
 * Dos cosas que la fuente **no** dice y que esta clase decide, para no mostrar números inventados:
 *
 *  - una FUR **vacía o ilegible** no da edad gestacional (el escritorio, si el `SELECT` de la FUR falla, no
 *    escribe el campo);
 *  - una FUR **en el futuro** tampoco: `daysafter` daría días negativos y la cuenta del escritorio
 *    (`int()` de PowerBuilder trunca hacia cero) mostraría "0 Semanas y -5 días". Es un dato imposible, así
 *    que se trata como "sin edad gestacional" en vez de mostrar algo absurdo.
 *
 * La FUR del legado puede ser **absurda** (el dump tiene años 1012 y 2090): eso **no** se corrige acá. La
 * cuenta da lo que el dato dice; quien tenga que desconfiar del dato lo hace en la pantalla.
 */
final class EdadGestacional
{
    /**
     * **Los días que suma la FPP en TODA la web: 280** (WEB-11.16; decisión de Alexander, 2026-10-15). Es la única definición:
     * el control prenatal, el informe, los ecos y el listado de embarazos y partos la usan, así que cambiar este número cambia todo.
     *
     * El escritorio tenía tres valores (270, 281 y 283, ver abajo); la web los unificó en el del embarazo a término (40 semanas).
     */
    public const DIAS_DE_LA_FPP = 280;

    /**
     * (Histórico) Los días que sumaba el control prenatal en el escritorio: **281**. Hoy es un alias de {@see self::DIAS_DE_LA_FPP}.
     *
     *
     * ⚠️ **Cuidado con el DataWindow**: la pestaña viva del control prenatal es `d_pre_natal_observa_2.srd`
     * (lo asigna `w_consulta_principal.srw:24725`) y **suma 281** (`:38`). `d_pre_natal_observa.srd` —el
     * hermano sin `_2`— **no lo usa ninguna ventana**: es un huérfano y suma 270 («270» y «281», dos
     * archivos con el mismo nombre base y distinto número). El tercer número es el de los **reportes y los
     * ecos**: 283.
     *
     * Los tres están en `docs`/la wiki con su cita. La web conserva el número de **cada pantalla**.
     */
    public const DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL = self::DIAS_DE_LA_FPP;

    /** (Histórico) Los 283 del eco obstétrico (`relativedate(fur, 283)`). Hoy es un alias de {@see self::DIAS_DE_LA_FPP}. */
    public const DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO = self::DIAS_DE_LA_FPP;

    private function __construct(
        private CarbonInterface $fur,
        private int $diasDesdeLaFur,
    ) {
    }

    /**
     * La edad gestacional de una FUR, o `null` si no hay una FUR usable.
     *
     * @param  string|null  $fur  `AAAA-MM-DD` (o `DD/MM/AAAA`, como lo escriben algunos datos del legado)
     * @param  CarbonInterface|null  $hoy  el día de la cuenta; por defecto, hoy en la zona del consultorio
     */
    public static function de(?string $fur, ?CarbonInterface $hoy = null): ?self
    {
        $desde = self::fecha($fur);

        if ($desde === null) {
            return null;
        }

        $hoy = $hoy === null ? \App\Clinica\FechaDelConsultorio::hoyComoFecha() : $hoy;
        $dias = (int) $desde->startOfDay()->diffInDays($hoy->copy()->startOfDay(), false);

        // Una FUR futura no da edad gestacional (ver el docblock de la clase).
        return $dias < 0 ? null : new self($desde, $dias);
    }

    /**
     * La **FPP** de una FUR: la FUR más los días que suma esa pantalla (270 en el control prenatal, 283 en
     * el eco obstétrico). `null` si no hay FUR.
     *
     * @param  int  $dias  los días de la pantalla: usa las constantes de esta clase
     */
    public static function fpp(?string $fur, int $dias): ?string
    {
        $desde = self::fecha($fur);

        return $desde === null ? null : $desde->copy()->addDays($dias)->toDateString();
    }

    /** Los días que han pasado desde la FUR: el `daysafter(fur, today())` del escritorio. */
    public function diasTotales(): int
    {
        return $this->diasDesdeLaFur;
    }

    /** Las **semanas** completas: `int(días / 7)`. */
    public function semanas(): int
    {
        return intdiv($this->diasDesdeLaFur, 7);
    }

    /** Los **días** que sobran: `mod(días, 7)`. */
    public function dias(): int
    {
        return $this->diasDesdeLaFur % 7;
    }

    /**
     * La edad gestacional como la lee el médico: "6 semanas y 1 día".
     *
     * El escritorio la arma pegando los textos del DataWindow —`' Semanas y '` y `' días'`—, así que muestra
     * "6 Semanas y 1 días": acá se escribe en castellano (minúscula y singular cuando toca), como el resto
     * de los textos de la web. La cuenta es la misma, carácter por carácter.
     */
    public function texto(): string
    {
        return $this->semanas() . ' ' . ($this->semanas() === 1 ? 'semana' : 'semanas')
            . ' y ' . $this->dias() . ' ' . ($this->dias() === 1 ? 'día' : 'días');
    }

    /** La FUR con la que se calculó, en `AAAA-MM-DD`. */
    public function fur(): string
    {
        return $this->fur->toDateString();
    }

    /** La fecha de la FUR, o `null` si el valor no es una fecha usable. */
    private static function fecha(?string $fur): ?CarbonInterface
    {
        $fur = trim((string) $fur);

        if ($fur === '' || strcasecmp($fur, 'none') === 0) {
            return null;
        }

        try {
            // El legado guarda las fechas como `AAAA-MM-DD`; en algunos datos viejos aparecen con barras.
            return Carbon::parse(str_replace('/', '-', $fur))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
