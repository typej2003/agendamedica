<?php

namespace App\Clinica\Crecimiento\Vistas;

use App\Clinica\Crecimiento\EdadEnTexto;
use App\Clinica\Crecimiento\GraficaDeCrecimiento;
use App\Clinica\Crecimiento\PercentilesDeCrecimiento;
use App\Clinica\Pacientes\CamposDePaciente;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;

/**
 * **El crecimiento de un niño** (WEB-7.4.3c): las gráficas de peso, talla, perímetro cefálico, IMC y peso para la talla con las tres curvas
 * de la referencia y los puntos de sus consultas, en tres juegos de edades (0 a 19, 0 a 5 y 5 a 19 años; por defecto el primero), y la tabla de sus
 * mediciones con dónde cae cada una.
 *
 * No consulta nada ni sabe de rutas: recibe lo leído (`CrecimientoDelPaciente`, la referencia ya armada) y una función que arma el enlace
 * de cada gráfica y juego. Si el consultorio no cargó la referencia, las gráficas se dibujan sólo con los puntos del niño y la pantalla lo dice.
 */
final class CrecimientoVista
{
    public const POR_DEFECTO = 'peso';
    public const POR_DEFECTO_JUEGO = '0-19';

    /** clave => [título, eje x, eje y, medida de la referencia, columna de la medición, tipo de eje x]. */
    private const GRAFICAS = [
        'peso' => ['Peso', 'Edad (años)', 'Peso (kg)', 'peso', 'peso', 'edad'],
        'talla' => ['Talla', 'Edad (años)', 'Talla (cm)', 'talla', 'talla', 'edad'],
        'cefalica' => ['Perímetro cefálico', 'Edad (años)', 'Perímetro cefálico (cm)', 'cefalica', 'c_cefalica', 'edad'],
        'imc' => ['IMC', 'Edad (años)', 'IMC (kg/m²)', 'imc', 'imc', 'edad'],
        'peso-talla' => ['Peso para la talla', 'Talla (cm)', 'Peso (kg)', 'peso', 'peso', 'talla'],
    ];

    /** clave => [desde, hasta (años), texto, marcas del eje x]. */
    private const JUEGOS = [
        '0-19' => [0.0, 19.0, '0 a 19 años', [0.0, 2.0, 4.0, 6.0, 8.0, 10.0, 12.0, 14.0, 16.0, 18.0, 19.0]],
        '0-5' => [0.0, 5.0, '0 a 5 años', [0.0, 1.0, 2.0, 3.0, 4.0, 5.0]],
        '5-19' => [5.0, 19.0, '5 a 19 años', [5.0, 7.0, 9.0, 11.0, 13.0, 15.0, 17.0, 19.0]],
    ];

    /**
     * @param  object  $paciente  `nombres`, `apellidos`, `sexo`, `fnacimiento` y `numhistoria`
     * @param  Collection<int,object>  $mediciones  lo que devolvió `CrecimientoDelPaciente::de()`
     * @param  Closure(string,string):string  $url  arma el enlace de una gráfica y un juego
     */
    public function __construct(
        private object $paciente,
        private Collection $mediciones,
        private PercentilesDeCrecimiento $percentiles,
        private string $grafica,
        private string $juego,
        private Closure $url,
        private string $urlFicha,
        private string $urlImprimir = '',
        private ?string $hoy = null,
    ) {
    }

    // ---------------------------------------------------------------------------------- Elección

    public static function normalizarGrafica(?string $clave): string
    {
        return isset(self::GRAFICAS[(string) $clave]) ? (string) $clave : self::POR_DEFECTO;
    }

    /** El juego que se pide, o —si no se pidió ninguno— todas las edades (0 a 19 años). */
    public static function normalizarJuego(?string $clave, ?string $nacimiento, ?string $hoy = null): string
    {
        if (isset(self::JUEGOS[(string) $clave])) {
            return (string) $clave;
        }

        return self::POR_DEFECTO_JUEGO;
    }

    public function graficaActiva(): string
    {
        return $this->grafica;
    }

    public function juegoActivo(): string
    {
        return $this->juego;
    }

    // ---------------------------------------------------------------------------------- Encabezado

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos ?? null, $this->paciente->nombres ?? null);
    }

    public function subtitulo(): string
    {
        $partes = ['Historia H. ' . ($this->paciente->numhistoria ?? '—')];

        $edad = EdadEnTexto::entre($this->paciente->fnacimiento ?? null, $this->hoy ?? \App\Clinica\FechaDelConsultorio::hoyComoTexto());
        if ($edad !== null) {
            $partes[] = $edad;
        }

        $sexo = match (strtoupper((string) ($this->paciente->sexo ?? ''))) {
            'M' => 'Niño', 'F' => 'Niña', default => null,
        };
        if ($sexo !== null) {
            $partes[] = $sexo;
        }

        return implode(' · ', $partes);
    }

    public function urlFicha(): string
    {
        return $this->urlFicha;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    // ---------------------------------------------------------------------------------- Navegación

    /** @return array<int,array{texto:string, url:string, activa:bool}> los chips de la gráfica */
    public function chips(): array
    {
        $chips = [];
        foreach (self::GRAFICAS as $clave => $definicion) {
            $chips[] = ['texto' => $definicion[0], 'url' => ($this->url)($clave, $this->juego), 'activa' => $clave === $this->grafica];
        }

        return $chips;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> el segmentado del juego de edades */
    public function juegos(): array
    {
        $juegos = [];
        foreach (self::JUEGOS as $clave => $definicion) {
            $juegos[] = ['texto' => $definicion[2], 'url' => ($this->url)($this->grafica, $clave), 'activa' => $clave === $this->juego];
        }

        return $juegos;
    }

    public function textoDelJuego(): string
    {
        return self::JUEGOS[$this->juego][2];
    }

    // ---------------------------------------------------------------------------------- Gráficas

    public function grafica(): GraficaDeCrecimiento
    {
        return $this->armar($this->grafica, $this->juego);
    }

    /** Todas las gráficas del juego activo, para la hoja impresa. @return array<string,GraficaDeCrecimiento> */
    public function todas(): array
    {
        $todas = [];
        foreach (array_keys(self::GRAFICAS) as $clave) {
            $todas[$clave] = $this->armar($clave, $this->juego);
        }

        return $todas;
    }

    private function armar(string $clave, string $juego): GraficaDeCrecimiento
    {
        [$titulo, $ejeX, $ejeY, $medida, $columna, $tipo] = self::GRAFICAS[$clave];
        [$desde, $hasta, , $marcas] = self::JUEGOS[$juego];
        $sexo = $this->paciente->sexo ?? null;

        $puntos = [];
        foreach ($this->mediciones as $m) {
            $anos = $this->anos($m->fecha);
            $y = $m->{$columna};

            // Un 0 es «sin medir» (el escritorio y el formulario lo guardan así): no se grafica.
            if ($anos === null || $anos < $desde || $anos > $hasta || $y === null || (float) $y <= 0) {
                continue;
            }

            if ($tipo === 'edad') {
                $x = $anos;
            } elseif ($m->talla !== null && (float) $m->talla > 0) {
                $x = (float) $m->talla;
            } else {
                continue;
            }

            $puntos[] = [
                'x' => (float) $x,
                'y' => (float) $y,
                'etiqueta' => self::fecha($m->fecha) . ' · ' . EdadEnTexto::entre($this->paciente->fnacimiento ?? null, $m->fecha)
                    . ' · ' . $ejeY . ': ' . GraficaDeCrecimiento::numero((float) $y)
                    . ($tipo === 'talla' ? ' · talla ' . GraficaDeCrecimiento::numero((float) $x) . ' cm' : ''),
            ];
        }

        if ($tipo === 'edad') {
            $curvas = ['b' => [], 'm' => [], 'a' => []];
            foreach ($this->percentiles->puntosDeBandas($medida, $sexo, $desde, $hasta) as $p) {
                foreach (['b', 'm', 'a'] as $curva) {
                    $curvas[$curva][] = ['x' => $p['anos'], 'y' => $p[$curva]];
                }
            }
        } else {
            $curvas = [];
            foreach ($this->percentiles->curvasDePesoPorTalla($sexo, $desde, $hasta) as $curva => $lista) {
                $curvas[$curva] = array_map(fn ($p) => ['x' => $p['talla'], 'y' => $p['peso']], $lista);
            }
        }

        return new GraficaDeCrecimiento(
            $titulo . ' · ' . self::JUEGOS[$juego][2],
            $ejeX,
            $ejeY,
            $curvas,
            $puntos,
            $tipo === 'edad' ? [$desde, $hasta] : null,
            $tipo === 'edad' ? $marcas : null,
            // La fuente de las curvas (O-37): sale de la referencia que se dibuja, no del Blade.
            $this->percentiles->fuente(),
        );
    }

    // ---------------------------------------------------------------------------------- Estados

    public function hayMediciones(): bool
    {
        return $this->mediciones->isNotEmpty();
    }

    public function hayReferencia(): bool
    {
        return $this->percentiles->hayReferencia($this->paciente->sexo ?? null);
    }

    /** @return array{titulo:string, texto:string}|null lo que se dice cuando no hay referencia con la que comparar */
    public function avisoDeReferencia(): ?array
    {
        if (PercentilesDeCrecimiento::sexoDeReferencia($this->paciente->sexo ?? null) === null) {
            return ['titulo' => 'Falta el sexo del niño', 'texto' => 'Sin el sexo en la ficha no se sabe con qué curvas comparar. Las mediciones se dibujan igual.'];
        }

        if (! $this->hayReferencia()) {
            return [
                'titulo' => 'Sin curvas de referencia',
                'texto' => 'Este consultorio todavía no tiene cargadas las curvas de referencia de crecimiento (se traen con la carga inicial de pediatría). '
                    . 'Las mediciones se dibujan igual, pero sin las curvas baja, media y alta.',
            ];
        }

        return null;
    }

    /** @return array{titulo:string, texto:string} el estado vacío cuando el niño no tiene mediciones */
    public function sinMediciones(): array
    {
        return [
            'titulo' => 'Todavía no hay mediciones',
            'texto' => 'El peso, la talla y los perímetros se registran en cada consulta, en Evaluación física → Mediciones. '
                . 'Cuando haya algunas, aquí se dibuja su curva de crecimiento.',
        ];
    }

    /** ¿La gráfica activa no tiene ningún punto del niño en este juego de edades? */
    public function sinPuntosEnElJuego(): bool
    {
        return $this->hayMediciones() && ! $this->grafica()->conPuntos();
    }

    // ---------------------------------------------------------------------------------- Tabla

    /** @return array<int,string> los rótulos de la tabla */
    public function columnas(): array
    {
        return ['Fecha', 'Edad', 'Peso (kg)', 'Talla (cm)', 'P. cefálico (cm)', 'IMC', 'Pulso', 'PA'];
    }

    /**
     * Las mediciones, de la más nueva a la más antigua, con dónde cae cada una respecto a las curvas.
     *
     * @return array<int,array{fecha:string, edad:string, celdas:array<int,array{valor:string, posicion:?string, fuera:bool}>}>
     */
    public function filas(): array
    {
        $sexo = $this->paciente->sexo ?? null;
        $filas = [];

        foreach ($this->mediciones->reverse() as $m) {
            $dias = PercentilesDeCrecimiento::diasDeEdad($this->paciente->fnacimiento ?? null, $m->fecha);
            $celdas = [];

            foreach ([['peso', 'peso'], ['talla', 'talla'], ['c_cefalica', 'cefalica'], ['imc', 'imc']] as [$columna, $medida]) {
                $valor = $m->{$columna};
                $banda = $valor === null ? null : $this->percentiles->banda($medida, (float) $valor, $sexo, $dias);
                $celdas[] = [
                    'valor' => $valor === null ? '—' : GraficaDeCrecimiento::numero((float) $valor),
                    'posicion' => $banda?->corto(),
                    'fuera' => $banda !== null && ! $banda->enRango(),
                ];
            }

            $celdas[] = ['valor' => $m->pulso === null ? '—' : GraficaDeCrecimiento::numero((float) $m->pulso), 'posicion' => null, 'fuera' => false];
            $celdas[] = [
                'valor' => $m->p_a_sistolica === null && $m->p_a_diastolica === null
                    ? '—'
                    : GraficaDeCrecimiento::numero((float) $m->p_a_sistolica) . '/' . GraficaDeCrecimiento::numero((float) $m->p_a_diastolica),
                'posicion' => null,
                'fuera' => false,
            ];

            $filas[] = [
                'fecha' => self::fecha($m->fecha),
                'edad' => EdadEnTexto::entre($this->paciente->fnacimiento ?? null, $m->fecha) ?? '—',
                'celdas' => $celdas,
            ];
        }

        return $filas;
    }

    /** La fecha de hoy, para el encabezado de la hoja impresa. */
    public function fechaDeHoy(): string
    {
        return self::fecha($this->hoy ?? \App\Clinica\FechaDelConsultorio::hoyComoTexto());
    }

    // ---------------------------------------------------------------------------------- Adentro

    /** La edad en años a una fecha, o `null` si no se puede calcular. */
    private function anos(?string $fecha): ?float
    {
        $dias = PercentilesDeCrecimiento::diasDeEdad($this->paciente->fnacimiento ?? null, $fecha);

        return $dias === null ? null : round($dias / 365.25, 3);
    }

    private static function fecha(?string $fecha): string
    {
        return $fecha === null ? '—' : Carbon::parse($fecha)->format('d/m/Y');
    }
}
