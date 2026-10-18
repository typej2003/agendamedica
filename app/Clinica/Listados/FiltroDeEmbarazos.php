<?php

namespace App\Clinica\Listados;

use App\Clinica\Embarazo\EdadGestacional;
use Carbon\CarbonInterface;

/**
 * **Los filtros del listado de embarazos / partos** (WEB-6.8), ya normalizados, con la semántica exacta del
 * escritorio.
 *
 * El reporte del legado es `w_reporte_consulta_partos.srw`, que el *Listado de Partos* de
 * `w_reportes_consultas.srw:449` abre con dos formas —`s_tipo` `21` (por rango) y `22` (por semana)— sobre
 * dos DataWindows gemelos (`d_partos` y `d_partos_semana`). Las dos filtran lo mismo salvo la FUR:
 *
 * | Filtro | Cómo lo hace el legado |
 * |---|---|
 * | **Desde / Hasta** | rango corrido **−283 días** y aplicado a `pre_natal_observaciones.fur` (`w_reportes_consultas.srw:1312-1313`, `d_partos.srd`): equivale a la **FPP dentro del rango** |
 * | **Edad inicial / final** | `(today − pacientes.fnacimiento)/365 between :edad_1 and :edad_2`; por defecto **1..110** |
 * | **Por semanas** | `(today − fur)/7 = :semana`, división entera, con la semana validada **1..40** (`:1295`) |
 * | **Fijo** | `pre_natal_observaciones.final = 'N'` — ver `EmbarazosEntreFechas`, que lo replica sin interpretarlo |
 * | **Orden** | FPP descendente: `d_partos.srd` `sort="compute_1 D"`, con `compute_1 = relativedate(fur, 283)` |
 *
 * Lo que esta clase decide **fuera** del legado, y por qué:
 *
 *  - **Los 283 son los de los reportes.** El número no se inventa ni se elige: es el mismo que documenta
 *    `EdadGestacional` (los reportes y los ecos suman 283; la pestaña viva del control prenatal suma 281),
 *    así que se toma prestada su constante en vez de escribir un cuarto número suelto.
 *  - **El rango al revés y la semana que no se entiende no bloquean**: se dan vuelta, o se marcan como
 *    inválidos, y la pantalla lo dice. El escritorio cortaba las dos con un `Messagebox` (`:1283`, `:1287`,
 *    `:1296`).
 *  - **La edad se traduce a fechas de nacimiento** con `RangoDeFechas::nacimientosDeLasEdades`: la misma
 *    cuenta de años cumplidos que usan el listado de pacientes y la columna "Edad" de este reporte, para que
 *    el filtro y lo que se lee no se contradigan.
 */
final class FiltroDeEmbarazos
{
    /**
     * Los días que los **reportes** le suman a la FUR para la FPP.
     *
     * Es el **283** del eco obstétrico (`EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO`) y de
     * `relativedate(fur, 283)` de los dos DataWindows de este reporte: la constante se reusa en vez de
     * repetir el número, y la clase que documenta los tres valores del legado sigue siendo una sola.
     */
    public const DIAS_DE_LA_FPP = EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO;

    /** Las edades con las que nace el reporte en el escritorio (`em_5`/`em_6`). */
    public const EDAD_INICIAL_POR_DEFECTO = RangoDeEdades::INICIAL_POR_DEFECTO;
    public const EDAD_FINAL_POR_DEFECTO = RangoDeEdades::FINAL_POR_DEFECTO;

    /** La semana que el legado acepta en el modo "Por Semanas" (`w_reportes_consultas.srw:1295`). */
    public const SEMANA_MINIMA = 1;
    public const SEMANA_MAXIMA = 40;

    private function __construct(
        private RangoDeFechas $rango,
        private int $edadInicial,
        private int $edadFinal,
        private bool $porSemanas,
        private ?int $semana,
        private string $semanaPedida,
        private bool $semanaInvalida,
        private bool $seVoltearonLasEdades,
        private bool $hayFiltro,
        private CarbonInterface $hoy,
    ) {
    }

    /**
     * Los filtros de una petición, normalizados. `$hoy` es el día del consultorio (`FechaDelConsultorio`):
     * de él salen la fecha final por defecto y la ventana de la semana.
     *
     * @param  array<string,mixed>  $entrada  el `?desde=…&hasta=…&edad_inicial=…` de la pantalla
     */
    public static function de(array $entrada, CarbonInterface $hoy): self
    {
        $rango = RangoDeFechas::de(self::texto($entrada['desde'] ?? null), self::texto($entrada['hasta'] ?? null), $hoy);

        $porSemanas = filter_var($entrada['por_semanas'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $semanaPedida = self::texto($entrada['semana'] ?? null) ?? '';
        $semana = self::semanaValida($semanaPedida);

        // La cuenta de las dos edades —el rango, el orden y el techo— vive en `RangoDeEdades`, que es la
        // misma que usa el listado de pacientes por rango: acá sólo se guarda el resultado.
        $edades = RangoDeEdades::de($entrada['edad_inicial'] ?? null, $entrada['edad_final'] ?? null);

        // "Limpiar" se ofrece sólo si algo se movió de los valores con los que nace la pantalla: el formulario
        // manda todos los campos siempre, así que la presencia de un parámetro no dice nada.
        $porDefecto = RangoDeFechas::de(null, null, $hoy);
        $hayFiltro = $porSemanas
            || $rango->desde() !== $porDefecto->desde()
            || $rango->hasta() !== $porDefecto->hasta()
            || ! $edades->sonLasDePorDefecto();

        return new self(
            $rango,
            $edades->inicial(),
            $edades->final(),
            $porSemanas,
            $semana,
            $semanaPedida,
            $porSemanas && $semana === null,
            $edades->seVoltearon(),
            $hayFiltro,
            $hoy,
        );
    }

    /** El rango de fechas elegido (el de la **FPP**, no el de la FUR). */
    public function rango(): RangoDeFechas
    {
        return $this->rango;
    }

    /**
     * **El rango de FUR que se filtra**, según el modo: la FPP elegida corrida −283, o la ventana de siete
     * días de la semana pedida. Es la única traducción entre lo que el médico elige y la columna que se
     * consulta.
     */
    public function rangoDeFur(): RangoDeFechas
    {
        if ($this->porSemanas && $this->semana !== null) {
            return RangoDeFechas::furDeLaSemanaDeGestacion($this->hoy, $this->semana);
        }

        return $this->rango->furDeLaFpp(self::DIAS_DE_LA_FPP);
    }

    /** El rango de fechas de nacimiento que corresponde a las edades pedidas. */
    public function rangoDeNacimiento(): RangoDeFechas
    {
        return RangoDeFechas::nacimientosDeLasEdades($this->hoy, $this->edadInicial, $this->edadFinal);
    }

    public function edadInicial(): int
    {
        return $this->edadInicial;
    }

    public function edadFinal(): int
    {
        return $this->edadFinal;
    }

    /** ¿Se pidió el listado **por semanas**? (el "Por Semanas" del escritorio). */
    public function porSemanas(): bool
    {
        return $this->porSemanas;
    }

    /** La semana pedida, o `null` si no se pidió una válida. */
    public function semana(): ?int
    {
        return $this->semana;
    }

    /** La semana tal como llegó, para volver a escribirla en el formulario sin perder lo tecleado. */
    public function semanaPedida(): string
    {
        return $this->semanaPedida;
    }

    /** Se pidió "Por Semanas" y la semana no es un número de 1 a 40: no hay nada que buscar. */
    public function semanaInvalida(): bool
    {
        return $this->semanaInvalida;
    }

    /** ¿Se puede consultar con estos filtros? La semana que no se entiende es el único caso que no. */
    public function esValido(): bool
    {
        return ! $this->semanaInvalida;
    }

    public function seVoltearonLasFechas(): bool
    {
        return $this->rango->seVolteo();
    }

    public function seVoltearonLasEdades(): bool
    {
        return $this->seVoltearonLasEdades;
    }

    /** ¿Hay algún filtro puesto (dejó de ser el que trae la pantalla)? Lo usa "Limpiar". */
    public function hayFiltro(): bool
    {
        return $this->hayFiltro;
    }

    /** La semana pedida, si es un número de 1 a 40; `null` en cualquier otro caso. */
    private static function semanaValida(string $valor): ?int
    {
        if (! preg_match('/^\d{1,2}$/', $valor)) {
            return null;
        }

        $semana = (int) $valor;

        return $semana >= self::SEMANA_MINIMA && $semana <= self::SEMANA_MAXIMA ? $semana : null;
    }

    private static function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
