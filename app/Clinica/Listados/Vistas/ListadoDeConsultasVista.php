<?php

namespace App\Clinica\Listados\Vistas;

use App\Clinica\Listados\FiltroDeConsultas;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * **Lo que muestra el listado de consultas por rango** (WEB-6.8): los *Indicadores de Consultas* del escritorio
 * (`w_reporte_consulta_barbaro.srw`), ya formateados, con los filtros que se están mirando y los estados de la
 * pantalla.
 *
 * Las columnas son las del legado —**# Hist.**, **Paciente**, **Edad**, **Motivo de consulta**, **Diagnóstico** y
 * **Medicamento**, más **Antecedente** cuando se filtró por uno— con las decisiones del usuario (2026-10-14): **una
 * fila por consulta**, y por eso una columna **Fecha** (la de la consulta, que es la que filtra) y los motivos,
 * diagnósticos y medicamentos de la consulta **juntos en su celda**, uno por renglón, en vez de repetir al paciente
 * por cada combinación (ver `FiltroDeConsultas`).
 *
 *  - **# Hist.** es el número con ceros a la izquierda hasta cinco dígitos, como el legado numera.
 *  - **Paciente** es `Apellidos, Nombres`, como en toda la web.
 *  - **Edad** es la de `FechaClinica::edad()`, la misma que usa el filtro (que se traduce a fechas de nacimiento).
 *  - **Antecedente** muestra sólo los antecedentes del paciente que coinciden con lo que se pidió: los antecedentes
 *    son del paciente, no de la consulta, y listarlos todos en cada fila ahogaría la tabla.
 *
 * **Sin totales ni cortes** (el legado no los tiene); sólo se cuenta cuántas consultas se miran, para el subtítulo.
 * La clase no toca la base: las filas llegan leídas y las URLs las resuelve el controlador.
 */
final class ListadoDeConsultasVista
{
    private const DIGITOS_DE_LA_HISTORIA = 5;

    /**
     * @param  Collection<int,object>  $consultas  las filas de `ConsultasPorRango::filas()`
     * @param  string  $urlBase  el listado sin filtros (`route('clinica.listados', ['listado' => …])`)
     * @param  string  $urlImprimir  la hoja impresa con **los mismos filtros** que la pantalla
     * @param  callable(int):string|null  $urlDePaciente  la ficha del paciente, o `null` si no se enlaza
     * @param  string  $claveDelListado  la clave elegida (`ListadosDisponibles`)
     */
    public function __construct(
        private Collection $consultas,
        private FiltroDeConsultas $filtro,
        private string $urlBase = '/clinica/listados',
        private string $urlImprimir = '/clinica/listados/imprimir',
        private $urlDePaciente = null,
        private string $claveDelListado = ListadosDisponibles::CONSULTAS,
    ) {
    }

    public function titulo(): string
    {
        return ListadosDisponibles::titulo($this->claveDelListado);
    }

    /** "12 consultas · del 01/01/2000 al 14/10/2026 · de 1 a 110 años · diagnóstico: quiste." */
    public function subtitulo(): string
    {
        $total = $this->total();

        $partes = [
            $total === 1 ? '1 consulta' : $total . ' consultas',
            'del ' . $this->desdeFormateado() . ' al ' . $this->hastaFormateado(),
            'de ' . $this->filtro->edadInicial() . ' a ' . $this->filtro->edadFinal() . ' años',
        ];

        foreach ($this->filtrosDeContenido() as $filtro) {
            $partes[] = mb_strtolower($filtro['etiqueta']) . ': ' . $filtro['valor'];
        }

        return implode(' · ', $partes) . '.';
    }

    public function total(): int
    {
        return $this->consultas->count();
    }

    public function estaVacio(): bool
    {
        return $this->consultas->isEmpty();
    }

    /** ¿Hay algo puesto que se pueda limpiar? Lo usa el enlace «Limpiar» del formulario. */
    public function hayFiltro(): bool
    {
        return $this->filtro->hayFiltro();
    }

    /** ¿Se filtró por antecedente? Entonces la tabla lleva su columna. */
    public function muestraAntecedentes(): bool
    {
        return $this->filtro->antecedente() !== null;
    }

    /**
     * Las columnas de listas de la tabla (una por renglón en la celda), en el orden del legado y con la clave de
     * `filas()`: motivo, antecedente —sólo si se filtró por uno—, diagnóstico y medicamento.
     *
     * @return array<string,string> `clave => etiqueta`
     */
    public function columnasDeListas(): array
    {
        $columnas = ['motivos' => 'Motivo de consulta'];

        if ($this->muestraAntecedentes()) {
            $columnas['antecedentes'] = 'Antecedente';
        }

        return $columnas + ['diagnosticos' => 'Diagnóstico', 'medicamentos' => 'Medicamento'];
    }

    // ------------------------------------------------------------------------------------- El formulario

    public function desde(): string
    {
        return $this->filtro->rangoDeConsulta()->desde();
    }

    public function hasta(): string
    {
        return $this->filtro->rangoDeConsulta()->hasta();
    }

    public function edadInicial(): int
    {
        return $this->filtro->edadInicial();
    }

    public function edadFinal(): int
    {
        return $this->filtro->edadFinal();
    }

    public function motivo(): string
    {
        return (string) $this->filtro->motivo();
    }

    public function diagnostico(): string
    {
        return (string) $this->filtro->diagnostico();
    }

    public function medicamento(): string
    {
        return (string) $this->filtro->medicamento();
    }

    public function antecedente(): string
    {
        return (string) $this->filtro->antecedente();
    }

    /**
     * Las filas de la tabla, ya formateadas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        return $this->consultas->map(function ($consulta) {
            $edad = FechaClinica::edad($consulta->fnacimiento);

            return [
                'historia'     => $this->historia($consulta->numhistoria ?? null),
                'nombre'       => CamposDePaciente::nombre($consulta->apellidos, $consulta->nombres),
                'url'          => $this->urlDePaciente !== null ? ($this->urlDePaciente)((int) $consulta->paciente_id) : null,
                'edad'         => $edad !== null ? $edad . ($edad === 1 ? ' año' : ' años') : null,
                'fecha'        => FechaClinica::formato($consulta->fecha),
                'motivos'      => array_values($consulta->motivos ?? []),
                'diagnosticos' => array_values($consulta->diagnosticos ?? []),
                'medicamentos' => array_values($consulta->medicamentos ?? []),
                'antecedentes' => array_values($consulta->antecedentes ?? []),
            ];
        })->all();
    }

    /**
     * El estado vacío, que distingue «no hay consultas con esos filtros» de «todavía no hay consultas».
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if ($this->hayFiltro()) {
            return [
                'icono'  => 'bi-search',
                'titulo' => 'Ninguna consulta con esos filtros',
                'texto'  => 'Prueba con otro rango de fechas o de edad, o con menos palabras en el motivo, el diagnóstico, '
                    . 'el medicamento o el antecedente. Un paciente sin fecha de nacimiento no aparece mientras se filtre por edad.',
            ];
        }

        return [
            'icono'  => 'bi-journal-medical',
            'titulo' => 'Todavía no hay consultas',
            'texto'  => 'Las consultas aparecen acá cuando se atiende a un paciente.',
        ];
    }

    /** Lo que hay que avisar de los filtros: las fechas o las edades que venían al revés. `null` si nada. */
    public function aviso(): ?string
    {
        $avisos = [];

        if ($this->filtro->seVoltearonLasFechas()) {
            $avisos[] = 'Las fechas venían al revés: se buscó del ' . $this->desdeFormateado()
                . ' al ' . $this->hastaFormateado() . '.';
        }

        if ($this->filtro->seVoltearonLasEdades()) {
            $avisos[] = 'Las edades venían al revés: se buscó de ' . $this->filtro->edadInicial()
                . ' a ' . $this->filtro->edadFinal() . ' años.';
        }

        return $avisos === [] ? null : implode(' ', $avisos);
    }

    /**
     * Los filtros como pares etiqueta/valor, para la cabecera de la hoja impresa: el rango y la edad van siempre;
     * motivo, diagnóstico, medicamento y antecedente, si se pidieron.
     *
     * @return array<int,array{etiqueta:string,valor:string}>
     */
    public function descripcionDeLosFiltros(): array
    {
        return array_merge([
            ['etiqueta' => 'Desde', 'valor' => $this->desdeFormateado()],
            ['etiqueta' => 'Hasta', 'valor' => $this->hastaFormateado()],
            ['etiqueta' => 'Edad', 'valor' => $this->filtro->edadInicial() . ' a ' . $this->filtro->edadFinal() . ' años'],
        ], $this->filtrosDeContenido());
    }

    /** Cómo se busca lo escrito, dicho en la pantalla. */
    public function notaDeLaBusqueda(): string
    {
        return 'Motivo, diagnóstico, medicamento y antecedente se buscan por el nombre: escribe una parte, sin importar '
            . 'acentos ni mayúsculas (si son varias palabras, tienen que estar todas). La fecha es la de la consulta.';
    }

    public function urlBase(): string
    {
        return $this->urlBase;
    }

    /** La clave del listado elegido: el formulario la lleva en un campo oculto para no perderla al filtrar. */
    public function claveDelListado(): string
    {
        return $this->claveDelListado;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return array<int,array{etiqueta:string,valor:string}> motivo, diagnóstico, medicamento y antecedente pedidos */
    private function filtrosDeContenido(): array
    {
        $filtros = [];

        foreach ([
            'Motivo'       => $this->filtro->motivo(),
            'Diagnóstico'  => $this->filtro->diagnostico(),
            'Medicamento'  => $this->filtro->medicamento(),
            'Antecedente'  => $this->filtro->antecedente(),
        ] as $etiqueta => $valor) {
            if ($valor !== null) {
                $filtros[] = ['etiqueta' => $etiqueta, 'valor' => $valor];
            }
        }

        return $filtros;
    }

    private function historia($valor): ?string
    {
        $numero = CamposDePaciente::numeroDeHistoria($valor);

        if ($numero === null) {
            return null;
        }

        return ctype_digit($numero) ? str_pad($numero, self::DIGITOS_DE_LA_HISTORIA, '0', STR_PAD_LEFT) : $numero;
    }

    private function desdeFormateado(): string
    {
        return FechaClinica::formato($this->filtro->rangoDeConsulta()->desde());
    }

    private function hastaFormateado(): string
    {
        return FechaClinica::formato($this->filtro->rangoDeConsulta()->hasta());
    }
}
