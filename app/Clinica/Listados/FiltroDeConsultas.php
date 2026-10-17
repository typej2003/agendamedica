<?php

namespace App\Clinica\Listados;

use Carbon\CarbonInterface;

/**
 * **Los filtros del listado de consultas por rango** (WEB-6.8), ya normalizados.
 *
 * El reporte del legado es *Indicadores de Consultas* (`w_reporte_consulta_barbaro.srw`, con 18 DataWindows
 * `d_reporte_barbaro_*` elegidos por la combinación de filtros que se llenaron): las consultas de los pacientes
 * entre dos edades y dos fechas, y —opcionales— con un **motivo**, un **antecedente**, un **diagnóstico** y un
 * **medicamento**. La web resuelve esa combinatoria con los filtros de una sola pantalla.
 *
 * **Decisiones del usuario (2026-10-14)**, que se apartan del legado a conciencia:
 *
 *  - **La fecha es la de la consulta** (`consultas.fecha`), no `pacientes.fingreso` como en el escritorio: el
 *    listado dice «consultas entre fechas» y eso es lo que filtra. Los defaults son los del escritorio:
 *    `01/01/2000` y hoy.
 *  - **Una fila por consulta**: el legado repite al paciente una vez por cada combinación de motivo ×
 *    antecedente × diagnóstico × medicamento (un producto cartesiano); acá cada consulta sale una vez, con sus
 *    motivos, diagnósticos y medicamentos juntos.
 *  - **Los cuatro filtros de contenido se escriben, no se eligen de un catálogo.** El legado los elegía de su
 *    catálogo con un buscador; un diagnóstico o un medicamento del vademécum son miles de opciones, así que acá se
 *    escribe parte del nombre (sin acentos ni mayúsculas, todas las palabras) y se buscan las consultas que lo
 *    tengan. Quien escribe «quiste» encuentra «Quiste de ovario» sin saber el código.
 *  - **El SMS a los pacientes del listado no se porta** (es una campaña masiva, fuera de esta salida).
 *
 * La **edad** se traduce a fechas de nacimiento con `RangoDeEdades`, la misma cuenta que usan los otros listados y
 * la columna «Edad». Un rango al revés no bloquea: se da vuelta y la pantalla lo avisa.
 */
final class FiltroDeConsultas
{
    /** Más largo que esto no es un nombre de motivo, de diagnóstico, de antecedente ni de medicamento. */
    public const LARGO_MAXIMO_DEL_TEXTO = 60;

    private function __construct(
        private RangoDeFechas $fechas,
        private RangoDeEdades $edades,
        private CarbonInterface $hoy,
        private ?string $motivo,
        private ?string $diagnostico,
        private ?string $medicamento,
        private ?string $antecedente,
        private bool $hayFiltro,
    ) {
    }

    /**
     * Los filtros de una petición, normalizados. `$hoy` es el día del consultorio.
     *
     * @param  array<string,mixed>  $entrada  el `?desde=…&hasta=…&edad_inicial=…&motivo=…` de la pantalla
     */
    public static function de(array $entrada, CarbonInterface $hoy): self
    {
        $fechas = RangoDeFechas::de(
            self::texto($entrada['desde'] ?? null),
            self::texto($entrada['hasta'] ?? null),
            $hoy,
        );
        $edades = RangoDeEdades::de($entrada['edad_inicial'] ?? null, $entrada['edad_final'] ?? null);

        $motivo = self::texto($entrada['motivo'] ?? null);
        $diagnostico = self::texto($entrada['diagnostico'] ?? null);
        $medicamento = self::texto($entrada['medicamento'] ?? null);
        $antecedente = self::texto($entrada['antecedente'] ?? null);

        // "Limpiar" se ofrece sólo si algo se movió de lo que trae la pantalla.
        $porDefecto = RangoDeFechas::de(null, null, $hoy);
        $hayFiltro = $fechas->desde() !== $porDefecto->desde()
            || $fechas->hasta() !== $porDefecto->hasta()
            || ! $edades->sonLasDePorDefecto()
            || $motivo !== null || $diagnostico !== null || $medicamento !== null || $antecedente !== null;

        return new self($fechas, $edades, $hoy, $motivo, $diagnostico, $medicamento, $antecedente, $hayFiltro);
    }

    /** El rango de **fecha de la consulta** (`consultas.fecha`), los dos extremos incluidos. */
    public function rangoDeConsulta(): RangoDeFechas
    {
        return $this->fechas;
    }

    /** El rango de fechas de nacimiento que corresponde a las edades pedidas. */
    public function rangoDeNacimiento(): RangoDeFechas
    {
        return $this->edades->rangoDeNacimiento($this->hoy);
    }

    public function edadInicial(): int
    {
        return $this->edades->inicial();
    }

    public function edadFinal(): int
    {
        return $this->edades->final();
    }

    public function motivo(): ?string
    {
        return $this->motivo;
    }

    public function diagnostico(): ?string
    {
        return $this->diagnostico;
    }

    public function medicamento(): ?string
    {
        return $this->medicamento;
    }

    public function antecedente(): ?string
    {
        return $this->antecedente;
    }

    public function seVoltearonLasFechas(): bool
    {
        return $this->fechas->seVolteo();
    }

    public function seVoltearonLasEdades(): bool
    {
        return $this->edades->seVoltearon();
    }

    /** ¿Hay algún filtro puesto (dejó de ser el que trae la pantalla)? Lo usa «Limpiar». */
    public function hayFiltro(): bool
    {
        return $this->hayFiltro;
    }

    /** El texto escrito, recortado y acotado; `null` si no hay nada. */
    private static function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        return mb_substr($texto, 0, self::LARGO_MAXIMO_DEL_TEXTO);
    }
}
