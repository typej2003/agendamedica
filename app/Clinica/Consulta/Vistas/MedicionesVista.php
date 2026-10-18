<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Crecimiento\EdadEnTexto;
use App\Clinica\Crecimiento\MedidasAntropometricas;
use App\Clinica\Crecimiento\PercentilesDeCrecimiento;
use App\Models\PercentilesPaciente;

/**
 * **Las mediciones de una consulta** (WEB-7.4.3a): la solapa *Mediciones* de la evaluación física de pediatría. Talla, peso, perímetro
 * cefálico, perímetro del brazo, pulso y presión arterial, con el **IMC y el «AC» calculados** (las fórmulas del escritorio, en
 * `MedidasAntropometricas`) y, si el consultorio cargó la referencia, **dónde cae cada medida** respecto a las curvas (WEB-7.4.3b).
 *
 * No consulta nada: recibe la fila ya leída, el paciente (nacimiento y sexo), la fecha de la consulta y el servicio de percentiles. Los
 * valores son lo que se acaba de escribir (`old()`, para no borrar nada ante un error) o lo guardado.
 */
final class MedicionesVista
{
    /** columna => [rótulo, unidad, medida de las curvas ('' si no tiene), ayuda]. */
    private const CAMPOS = [
        'peso' => ['Peso', 'kg', 'peso'],
        'talla' => ['Talla', 'cm', 'talla'],
        'c_cefalica' => ['Perímetro cefálico', 'cm', 'cefalica'],
        'c_brazo' => ['Perímetro del brazo', 'cm', 'brazo'],
        'pulso' => ['Pulso', 'lpm', ''],
        'p_a_sistolica' => ['PA sistólica', 'mmHg', ''],
        'p_a_diastolica' => ['PA diastólica', 'mmHg', ''],
    ];

    /**
     * @param  array<string,mixed>  $old  el `old()` del formulario (`[]` en la primera carga)
     */
    public function __construct(
        private ?PercentilesPaciente $fila,
        private ?string $nacimiento,
        private ?string $sexo,
        private ?string $fechaDeLaConsulta,
        private PercentilesDeCrecimiento $percentiles,
        private string $urlGuardar,
        private string $urlVolver,
        private array $old = [],
        private ?string $urlCrecimiento = null,
    ) {
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** El enlace a las gráficas del niño (WEB-7.4.3c), o `null` si el módulo todavía no está disponible. */
    public function urlCrecimiento(): ?string
    {
        return $this->urlCrecimiento;
    }

    public function avisoSinGuardar(): string
    {
        return 'Tienes mediciones sin guardar. Si sales ahora se pierden.';
    }

    /** La edad del niño a la fecha de la consulta, como el escritorio: «2 años 4 meses 3 días». */
    public function edad(): ?string
    {
        return EdadEnTexto::entre($this->nacimiento, $this->fechaDeLaConsulta);
    }

    /**
     * Los campos del formulario, con su valor actual y, si hay referencia, dónde cae la medida.
     *
     * @return array<int,array{columna:string, etiqueta:string, unidad:string, valor:string, posicion:?string, fuera:bool}>
     */
    public function campos(): array
    {
        $campos = [];

        foreach (self::CAMPOS as $columna => [$etiqueta, $unidad, $medida]) {
            $valor = $this->valor($columna);
            $posicion = null;
            $fuera = false;

            if ($medida !== '') {
                $banda = $this->percentiles->banda($medida, self::aNumero($valor), $this->sexo, $this->dias());
                $posicion = $banda?->texto();
                $fuera = $banda !== null && ! $banda->enRango();
            } elseif ($columna === 'pulso') {
                $posicion = $this->percentiles->percentil(PercentilesDeCrecimiento::PULSO, self::aNumero($valor), $this->sexo, $this->dias())?->texto();
            } else {
                $tipo = $columna === 'p_a_sistolica' ? PercentilesDeCrecimiento::SISTOLICA : PercentilesDeCrecimiento::DIASTOLICA;
                $posicion = $this->percentiles->percentil($tipo, self::aNumero($valor), $this->sexo, $this->dias())?->texto();
            }

            $campos[] = [
                'columna' => $columna,
                'etiqueta' => $etiqueta,
                'unidad' => $unidad,
                'valor' => $valor,
                'posicion' => $posicion,
                'fuera' => $fuera,
            ];
        }

        return $campos;
    }

    /** El IMC calculado con lo que está en el formulario (`—` si falta el peso o la talla). */
    public function imc(): string
    {
        $imc = MedidasAntropometricas::imc(self::aNumero($this->valor('peso')), self::aNumero($this->valor('talla')));

        return $imc > 0 ? number_format($imc, 2, ',', '') : '—';
    }

    /** Dónde cae el IMC respecto a las curvas, o `null`. */
    public function posicionDelImc(): ?string
    {
        $imc = MedidasAntropometricas::imc(self::aNumero($this->valor('peso')), self::aNumero($this->valor('talla')));

        return $imc > 0 ? $this->percentiles->banda('imc', $imc, $this->sexo, $this->dias())?->texto() : null;
    }

    /** El «AC» del escritorio calculado con lo que está en el formulario (`—` si falta el peso o la talla). */
    public function ac(): string
    {
        $ac = MedidasAntropometricas::ac(self::aNumero($this->valor('peso')), self::aNumero($this->valor('talla')));

        return $ac > 0 ? number_format($ac, 2, ',', '') : '—';
    }

    /** ¿Hay referencia de crecimiento para el sexo del niño? */
    public function hayReferencia(): bool
    {
        return $this->percentiles->hayReferencia($this->sexo);
    }

    /** Lo que se dice cuando no se puede decir en qué curva cae una medida. */
    public function avisoDeReferencia(): string
    {
        if (PercentilesDeCrecimiento::sexoDeReferencia($this->sexo) === null) {
            return 'Falta el sexo del niño en su ficha: sin él no se puede comparar con las curvas de crecimiento.';
        }

        if ($this->dias() === null) {
            return 'Falta la fecha de nacimiento del niño en su ficha: sin ella no se puede comparar con las curvas de crecimiento.';
        }

        if (! $this->hayReferencia()) {
            return 'Este consultorio todavía no tiene cargadas las curvas de referencia de crecimiento: las mediciones se guardan igual, pero no se comparan.';
        }

        return 'La edad del niño queda fuera de las curvas de referencia cargadas.';
    }

    /** ¿Alguna medida ya se pudo comparar con las curvas? (si ninguna, se muestra el aviso de por qué) */
    public function comparado(): bool
    {
        foreach ($this->campos() as $campo) {
            if ($campo['posicion'] !== null) {
                return true;
            }
        }

        return $this->posicionDelImc() !== null;
    }

    /** ¿Ya hay mediciones guardadas en esta consulta? */
    public function conDatos(): bool
    {
        return $this->fila !== null;
    }

    private function dias(): ?int
    {
        return PercentilesDeCrecimiento::diasDeEdad($this->nacimiento, $this->fechaDeLaConsulta);
    }

    /** El valor para el campo: lo escrito (`old()`) o lo guardado, sin ceros de más. */
    private function valor(string $columna): string
    {
        if (array_key_exists($columna, $this->old)) {
            return trim((string) $this->old[$columna]);
        }

        $guardado = $this->fila?->{$columna};

        return $guardado === null ? '' : rtrim(rtrim(number_format((float) $guardado, 3, '.', ''), '0'), '.');
    }

    private static function aNumero(string $valor): ?float
    {
        $texto = str_replace(',', '.', trim($valor));

        return is_numeric($texto) && (float) $texto > 0 ? (float) $texto : null;
    }
}
