<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\Interna\DatosDelInternista;
use App\Clinica\Consulta\Interna\MapaDelInternista;

/**
 * **Las secciones propias de la consulta del internista** (MI-2 a MI-5): evaluación física, plan de diagnóstico, observaciones,
 * análisis evolutivo y evaluación pre-operatoria. Un solo ViewModel porque las cinco tienen la misma forma: pestañas, un
 * formulario de campos del `MapaDelInternista` y, según la sección, una hoja para imprimir, la evolución entre consultas o el
 * riesgo operatorio (que reusa el de cirugía pediátrica).
 *
 * Se llama `$seccion` en el parcial, como el resto de las secciones de la consulta.
 */
final class InternistaVista
{
    /** El ancho y el alto del dibujo de la evolución de un valor (unidades del `viewBox` del SVG). */
    public const ANCHO_GRAFICA = 300;
    public const ALTO_GRAFICA = 80;

    /**
     * @param  array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool}>  $pestanas
     * @param  array<string,?string>  $valores  lo guardado, por clave del campo
     * @param  array<string,mixed>  $old  el `old()` del formulario
     * @param  array<int,array{nroconsulta:int, fecha:?string, valores:array<string,?float>}>  $evolucion
     */
    public function __construct(
        private string $seccion,
        private string $pestana,
        private array $pestanas,
        private array $valores,
        private string $urlGuardar,
        private string $urlVolver,
        private array $old = [],
        private ?string $urlImprimir = null,
        private array $evolucion = [],
        private ?RiesgoQuirurgicoVista $riesgo = null,
        // Las plantillas del **examen funcional** (`formato_print` tipo `EF`, Alexander 2026-10-29).
        private array $plantillas = [],
        // La URL del «Actualizar» del resumen físico (el `ue_resume` del escritorio), o `null`.
        private ?string $urlResumen = null,
    ) {
    }

    public function titulo(): string
    {
        return MapaDelInternista::TITULOS[$this->seccion] ?? '';
    }

    public function icono(): string
    {
        return match ($this->seccion) {
            MapaDelInternista::SECCION_FISICO => 'bi-heart-pulse',
            MapaDelInternista::SECCION_PLAN => 'bi-clipboard2-pulse',
            MapaDelInternista::SECCION_OBSERVACIONES => 'bi-journal-text',
            MapaDelInternista::SECCION_ANALISIS => 'bi-graph-up',
            default => 'bi-scissors',
        };
    }

    /** El id del bloque, para los enlaces y las pruebas. */
    public function id(): string
    {
        return 'consulta-' . $this->seccion;
    }

    /** Las pestañas, o `[]` si la sección tiene una sola (no se dibuja la barra). */
    public function pestanas(): array
    {
        return count($this->pestanas) > 1 ? $this->pestanas : [];
    }

    public function esAnalisis(): bool
    {
        return $this->seccion === MapaDelInternista::SECCION_ANALISIS;
    }

    public function esRiesgo(): bool
    {
        return $this->pestana === MapaDelInternista::PESTANA_RIESGO;
    }

    /** ¿Es la solapa del **resumen físico**, la que arma «Actualizar» con los signos y el examen? */
    public function esResumen(): bool
    {
        return $this->pestana === 'resumen';
    }

    /**
     * **¿La pestaña ofrece plantillas de texto?** Sólo el examen funcional (tipo `EF`): las plantillas se copian a su
     * campo, como en las solapas de texto de la consulta y en el resumen del examen físico.
     */
    public function ofrecePlantillas(): bool
    {
        return $this->pestana === 'funcional';
    }

    /**
     * Las plantillas `formato_print` del médico para el examen funcional (tipo `EF`), listas para pintar.
     *
     * @return array<int,array{titulo:string, texto:string}>
     */
    public function plantillas(): array
    {
        return array_map(
            fn (object $plantilla) => ['titulo' => (string) $plantilla->titulo, 'texto' => (string) $plantilla->texto],
            $this->plantillas,
        );
    }

    /** El campo al que se copian las plantillas del examen funcional (`#interna-funcionae`). */
    public function campoDePlantillas(): string
    {
        $campos = MapaDelInternista::deLaPestana($this->pestana);

        return 'interna-' . ($campos[0]['clave'] ?? 'funcionae');
    }

    public function tipoDePlantillas(): string
    {
        return 'EF';
    }

    /**
     * **La URL de «Actualizar» del resumen físico** (el `ue_resume` del escritorio): arma el texto con los signos y el
     * examen ya guardados. `null` en las demás pestañas.
     */
    public function urlResumen(): ?string
    {
        return $this->urlResumen;
    }

    /** El riesgo operatorio (reusa la solapa de cirugía pediátrica: `_quirurgico-riesgo` lo lee con `riesgo()`). */
    public function riesgo(): ?RiesgoQuirurgicoVista
    {
        return $this->riesgo;
    }

    /**
     * Los campos de la pestaña, listos para pintar: clave, id, etiqueta, tipo, unidad, opciones, valor y el ancho de su
     * columna en la grilla.
     *
     * @return array<int,array<string,mixed>>
     */
    public function campos(): array
    {
        $examen = $this->pestana === 'examen';

        return array_map(function (array $campo) use ($examen) {
            $valor = array_key_exists($campo['clave'], $this->old)
                ? (string) (is_array($this->old[$campo['clave']]) ? end($this->old[$campo['clave']]) : $this->old[$campo['clave']])
                : (string) ($this->valores[$campo['clave']] ?? '');

            return [
                'clave' => $campo['clave'],
                'id' => 'interna-' . $campo['clave'],
                'etiqueta' => $campo['etiqueta'],
                'tipo' => $campo['tipo'],
                'unidad' => $campo['unidad'] ?? null,
                'max' => $campo['max'] ?? null,
                'opciones' => $campo['opciones'] ?? [],
                'valor' => $valor,
                'marcado' => $valor === 'S',
                'columna' => match (true) {
                    $campo['tipo'] === 'parrafo' && $examen => 'col-12 col-md-4',
                    $campo['tipo'] === 'parrafo' => 'col-12',
                    $campo['tipo'] === 'opcion' => 'col-12 col-md-6',
                    default => 'col-6 col-md-3',
                },
            ];
        }, MapaDelInternista::deLaPestana($this->pestana));
    }

    /** ¿La pestaña tiene formulario? (el análisis evolutivo y el riesgo no usan el de campos). */
    public function tieneFormulario(): bool
    {
        return ! $this->esAnalisis() && ! $this->esRiesgo() && MapaDelInternista::deLaPestana($this->pestana) !== [];
    }

    public function pestana(): string
    {
        return $this->pestana;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** La hoja para imprimir de esta pestaña (EKG, análisis evolutivo, evaluación pre-operatoria), o `null`. */
    public function urlImprimir(): ?string
    {
        return $this->urlImprimir;
    }

    public function textoGuardar(): string
    {
        return 'Guardar ' . mb_strtolower($this->tituloDeLaPestana());
    }

    public function tituloDeLaPestana(): string
    {
        return MapaDelInternista::PESTANAS[$this->seccion][$this->pestana] ?? $this->titulo();
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay datos sin guardar en ' . mb_strtolower($this->tituloDeLaPestana()) . '.';
    }

    // ------------------------------------------------------------------ El análisis evolutivo (MI-4)

    public function tieneEvolucion(): bool
    {
        return $this->evolucion !== [];
    }

    /**
     * Las consultas de la evolución, con la fecha legible: la cabecera de la tabla.
     *
     * @return array<int,array{nro:int, fecha:string}>
     */
    public function consultasDeLaEvolucion(): array
    {
        return array_map(fn (array $fila) => [
            'nro' => $fila['nroconsulta'],
            'fecha' => $fila['fecha'] ? date('d/m/Y', strtotime($fila['fecha'])) : '—',
        ], $this->evolucion);
    }

    /**
     * Una serie por valor con algún dato: su etiqueta, su unidad, los valores por consulta (texto, `—` si falta) y los puntos
     * de su línea (`x,y …`) para el SVG. Una serie con un solo valor dibuja un punto.
     *
     * @return array<int,array{etiqueta:string, unidad:?string, celdas:array<int,string>, puntos:string, marcas:array<int,array{x:float,y:float}>, ultimo:string}>
     */
    public function series(): array
    {
        $series = [];
        $total = count($this->evolucion);

        foreach (DatosDelInternista::camposDeLaEvolucion() as $campo) {
            $numeros = array_map(fn (array $fila) => $fila['valores'][$campo['clave']] ?? null, $this->evolucion);
            $conDato = array_filter($numeros, fn ($v) => $v !== null);

            if ($conDato === []) {
                continue;
            }

            $minimo = min($conDato);
            $maximo = max($conDato);
            $rango = $maximo - $minimo ?: 1.0;
            $marcas = [];

            foreach ($numeros as $indice => $numero) {
                if ($numero === null) {
                    continue;
                }

                $marcas[] = [
                    'x' => round($total > 1 ? 10 + ($indice * (self::ANCHO_GRAFICA - 20) / ($total - 1)) : self::ANCHO_GRAFICA / 2, 1),
                    'y' => round(self::ALTO_GRAFICA - 10 - (($numero - $minimo) / $rango) * (self::ALTO_GRAFICA - 20), 1),
                ];
            }

            $ultimo = end($conDato);

            $series[] = [
                'etiqueta' => $campo['etiqueta'],
                'unidad' => $campo['unidad'] ?? null,
                'celdas' => array_map(fn ($v) => $v === null ? '—' : DatosDelInternista::numeroLegible($v), $numeros),
                'puntos' => implode(' ', array_map(fn (array $m) => $m['x'] . ',' . $m['y'], $marcas)),
                'marcas' => $marcas,
                'ultimo' => DatosDelInternista::numeroLegible($ultimo) . ($campo['unidad'] ? ' ' . $campo['unidad'] : ''),
            ];
        }

        return $series;
    }

    public function viewBox(): string
    {
        return '0 0 ' . self::ANCHO_GRAFICA . ' ' . self::ALTO_GRAFICA;
    }
}
