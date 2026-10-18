<?php

namespace App\Clinica\Embarazo\Vistas;

use App\Clinica\Embarazo\EdadGestacional;
use App\Clinica\Embarazo\Gesta;
use App\Clinica\Embarazo\InformePrenatal;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PrenaExamenB;
use App\Support\FechaClinica;
use Closure;

/**
 * **La pestaña *Reporte* del control de embarazo** (WEB-6.6): lo que el escritorio imprime.
 *
 * El escritorio no muestra un informe en pantalla: esta pestaña es un botón que imprime `dw_66`
 * (`w_consulta_principal.srw:25144`, rótulo *Imprimir*). Así que acá se ve **el resumen de lo que la hoja
 * lleva** —la ficha de la gesta, los controles y el laboratorio— y el botón que la manda al motor de
 * documentos (`DocumentoImpreso::CONTROL_DE_EMBARAZO`), con la gesta que se está mirando.
 *
 * Las tablas salen del **mapa del dato** de cada una, igual que la hoja: lo que se ve acá es lo que se
 * imprime.
 */
final class ReporteVista
{
    /**
     * @param  array{gesta:Gesta, controles:\Illuminate\Support\Collection, laboratorios:\Illuminate\Support\Collection, hoy:\Carbon\CarbonInterface|null}  $partes
     * @param  array<int,Gesta>  $gestas  todas las gestas, para el selector
     * @param  Closure|null  $urlGesta  `fn(int $clave): string`
     */
    public function __construct(
        private ?array $partes = null,
        private array $gestas = [],
        private string $urlImprimir = '',
        private string $urlVolver = '',
        private ?Closure $urlGesta = null,
    ) {
    }

    /** ¿La historia tiene alguna gesta? Sin ninguna no hay informe que mostrar ni imprimir. */
    public function hayInforme(): bool
    {
        return $this->partes !== null;
    }

    /** ¿Falta la FUR de la gesta que se mira? Sin ella el informe sale sin edad gestacional ni FPP (WEB-11.17). */
    public function faltaLaFur(): bool
    {
        return $this->hayInforme() && $this->gesta()->fur() === null;
    }

    public function titulo(): string
    {
        return 'Informe del control de embarazo';
    }

    /**
     * La ayuda del cuerpo, **en una línea** (O-23): lo que es y de dónde salen sus datos. El detalle de la
     * hoja —y de las dos FPP— está más abajo, junto a lo que explica.
     */
    public function ayuda(): string
    {
        return 'Lo que sale en la hoja «CONTROL DE EMBARAZO»; los datos se cargan en las otras pestañas.';
    }

    /** La gesta que se está mirando. */
    public function gesta(): ?Gesta
    {
        return $this->partes['gesta'] ?? null;
    }

    /** El número de la gesta, para el encabezado. */
    public function clave(): ?int
    {
        return $this->gesta()?->clave();
    }

    /**
     * El selector de gestas, como en la pestaña *Prenatal*.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function gestas(): array
    {
        $opciones = [];

        foreach ($this->gestas as $gesta) {
            $opciones[] = [
                'texto' => 'Gesta ' . $gesta->clave(),
                'url' => $this->urlGesta === null ? '' : ($this->urlGesta)($gesta->clave()),
                'activa' => $gesta->clave() === $this->clave(),
            ];
        }

        return $opciones;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** La **FPP de la hoja**: la del informe, 283 días (`InformePrenatal`), no la de la pantalla. */
    public function fpp(): ?string
    {
        return (new InformePrenatal())->fpp($this->gesta()->fur());
    }

    public function edadGestacional(): ?string
    {
        return (new InformePrenatal())->edadGestacional($this->gesta(), $this->partes['hoy'])?->texto();
    }

    /**
     * La ficha de la gesta, en el orden del escritorio, sin los datos que no están.
     *
     * @return array<int,array{etiqueta:string, valor:string}>
     */
    public function ficha(): array
    {
        $gesta = $this->gesta();
        $valores = $gesta->valores();
        $fpp = $this->fpp();

        $ficha = [];
        foreach ([
            'FUR' => $gesta->fur(),
            'FPP' => $fpp,
            'Edad gestacional' => $this->edadGestacional(),
            'Menarquia' => $gesta->menarquia(),
            'Gestas' => $valores['gestas'] ?? null,
            'Partos' => $valores['partos'] ?? null,
            'Cesáreas' => $valores['cesarea'] ?? null,
            'Abortos' => $valores['abortos'] ?? null,
            'Otros' => $valores['otros'] ?? null,
            'Tipiaje (madre)' => $gesta->tipiaje(),
            'Tipiaje (padre)' => $gesta->tipiajeDeLaPareja(),
            'Final de embarazo' => ($valores['final'] ?? null) === 'S' ? 'Sí' : (($valores['final'] ?? null) === 'N' ? 'No' : null),
            'Embarazo múltiple' => ($valores['multiple'] ?? null) === 'S' ? 'Sí' : (($valores['multiple'] ?? null) === 'N' ? 'No' : null),
            'Cantidad' => $valores['cantidad'] ?? null,
        ] as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $ficha[] = ['etiqueta' => $etiqueta, 'valor' => (string) $valor];
            }
        }

        return $ficha;
    }

    public function observaciones(): ?string
    {
        return $this->gesta()->observaciones();
    }

    /**
     * Las filas de una de las dos tablas por fecha, con los campos que tienen algo.
     *
     * @param  'controles'|'laboratorios'  $cual
     * @return array<int,array{fecha:string, claves:array<int,array{etiqueta:string, valor:string}>}>
     */
    public function filas(string $cual): array
    {
        $campos = $cual === 'controles' ? PreNatalDesarrolloFino::campos() : PrenaExamenB::campos();
        $filas = [];

        foreach ($this->partes[$cual] as $fila) {
            $claves = [];
            foreach ($campos as $campo) {
                $valor = $fila->{$campo['columna']} ?? null;

                if ($valor !== null && (string) $valor !== '') {
                    $claves[] = ['etiqueta' => (string) $campo['etiqueta'], 'valor' => (string) $valor];
                }
            }

            $filas[] = ['fecha' => (string) $fila->fecha, 'claves' => $claves];
        }

        return $filas;
    }

    /** ¿Hay algo cargado en esa pestaña? (para no mostrar una tabla vacía) */
    public function hay(string $cual): bool
    {
        return count($this->partes[$cual]) > 0;
    }

    /** El estado de la pestaña: con qué se imprime la hoja. */
    public function estado(): array
    {
        if (! $this->hayInforme()) {
            return ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin gesta',
                'titulo' => 'Sin gestas no hay informe'];
        }

        $cuantos = count($this->partes['controles']) + count($this->partes['laboratorios']);

        return $cuantos === 0
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin datos de seguimiento',
                'titulo' => 'La hoja sale sólo con la ficha de la gesta']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle',
                'texto' => $cuantos . ($cuantos === 1 ? ' fecha cargada' : ' fechas cargadas'),
                'titulo' => 'Los controles y los laboratorios que salen en la hoja'];
    }

    /** La FPP de la pantalla del control prenatal (la de la pestaña *Prenatal*). */
    public function fppDeLaPantalla(): ?string
    {
        return EdadGestacional::fpp($this->gesta()->fur(), EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL);
    }

    /**
     * La nota del pie de la pestaña: **qué FPP se imprime**.
     *
     * Desde WEB-11.16 la web usa un solo número (280 días) en todas las pantallas, así que la hoja y la
     * pestaña *Prenatal* muestran la misma fecha. Antes el escritorio imprimía una (283) y la pantalla otra
     * (281) y la pestaña lo explicaba; hoy sólo queda decirlo cuando —por un dato viejo— las dos difieran.
     */
    public function notaDeLaFpp(): string
    {
        $deLaHoja = $this->fpp();
        $deLaPantalla = $this->fppDeLaPantalla();
        $formato = fn (?string $fecha) => $fecha ? FechaClinica::formato($fecha) : '—';

        if ($deLaHoja === $deLaPantalla) {
            return 'La hoja imprime la FPP de la gesta (' . $formato($deLaHoja) . ').';
        }

        return 'La hoja imprime la FPP del informe (' . $formato($deLaHoja) . '); la pestaña *Prenatal* '
            . 'muestra la suya (' . $formato($deLaPantalla) . ').';
    }
}
