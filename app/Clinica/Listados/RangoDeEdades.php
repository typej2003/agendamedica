<?php

namespace App\Clinica\Listados;

use Carbon\CarbonInterface;

/**
 * **Un rango de edades cerrado por los dos lados** (las dos incluidas) tal como lo pide un listado entre
 * fechas: los dos números ya normalizados, si venían al revés y el rango de **fechas de nacimiento** que les
 * corresponde.
 *
 * Existe porque los listados de `App\Clinica\Listados` comparten **la misma** cuenta de edad —el filtro "Entre
 * edad" del escritorio—: el de embarazos / partos (`FiltroDeEmbarazos`) y el de pacientes por rango
 * (`FiltroDePacientesPorRango`). La regla se escribe una sola vez acá y los dos la usan; el rango de fechas
 * sigue viviendo en `RangoDeFechas`, que es la clase que documenta las fechas del legado.
 *
 * **Cómo se lee cada número:** un valor vacío o ilegible vuelve al de por defecto —el `integer('')` del
 * escritorio daría 0, y acá se prefiere el valor del reporte antes que un 0 silencioso—, y uno absurdo se
 * recorta al techo (`MAXIMA`, 120 años). Es la misma vara que `FiltroDeEmbarazos` ya tenía.
 *
 * **Los valores por defecto son los de los reportes:** 1 y 110. ⚠️ El campo "E. Inicial:" del escritorio nace
 * literalmente en `0` (`w_reprotes_pacientes.srw:759`, `em_5`) y su "E. Final:" en `110` (`:739`, `em_6`); el
 * primer listado de la web fijó **1..110** —el bebé de 0 años no es un paciente de estos reportes— y este
 * listado usa la misma vara para que los dos digan lo mismo.
 */
final class RangoDeEdades
{
    /** Las edades con las que nacen los reportes de la web. */
    public const INICIAL_POR_DEFECTO = 1;
    public const FINAL_POR_DEFECTO = 110;

    /** Un valor más alto que esto no es una edad: se recorta (el campo del escritorio tiene tres dígitos). */
    public const MAXIMA = 120;

    private function __construct(
        private int $inicial,
        private int $final,
        private bool $seVoltearon = false,
    ) {
    }

    /** Las edades pedidas, normalizadas y con el orden corregido. */
    public static function de($inicial, $final): self
    {
        $primera = self::edad($inicial, self::INICIAL_POR_DEFECTO);
        $segunda = self::edad($final, self::FINAL_POR_DEFECTO);

        if ($primera > $segunda) {
            return new self($segunda, $primera, true);
        }

        return new self($primera, $segunda);
    }

    public function inicial(): int
    {
        return $this->inicial;
    }

    public function final(): int
    {
        return $this->final;
    }

    /** ¿Las edades venían al revés y hubo que darlas vuelta? La pantalla lo avisa. */
    public function seVoltearon(): bool
    {
        return $this->seVoltearon;
    }

    /** ¿Son las que trae el reporte por defecto? Lo usa "Limpiar" (un filtro que no cambió nada no se limpia). */
    public function sonLasDePorDefecto(): bool
    {
        return $this->inicial === self::INICIAL_POR_DEFECTO && $this->final === self::FINAL_POR_DEFECTO;
    }

    /**
     * El rango de **fechas de nacimiento** que corresponde a estas edades, para que el filtro SQL y la columna
     * "Edad" digan lo mismo. La cuenta es de `RangoDeFechas` (años cumplidos, con sus bordes).
     */
    public function rangoDeNacimiento(CarbonInterface $hoy): RangoDeFechas
    {
        return RangoDeFechas::nacimientosDeLasEdades($hoy, $this->inicial, $this->final);
    }

    /**
     * La edad pedida, acotada a un rango razonable. Un valor vacío o ilegible vuelve al de por defecto: el
     * `integer('')` del escritorio daría 0, y un 0 silencioso filtraría de más.
     */
    private static function edad($valor, int $porDefecto): int
    {
        $texto = trim((string) $valor);

        if ($texto === '' || ! preg_match('/^\d{1,3}$/', $texto)) {
            return $porDefecto;
        }

        return min((int) $texto, self::MAXIMA);
    }
}
