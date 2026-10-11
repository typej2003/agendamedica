<?php

namespace App\Clinica\Historia\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Closure;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la **historia del paciente** (`/clinica/pacientes/{id}/historia`, WEB-3.5): las consultas
 * de su historia con sus datos clave, sus antecedentes agrupados por tipo y sus documentos.
 *
 * Es una pantalla de **sólo lectura** —la escritura de la consulta es F4 y la de los documentos, F5—, así
 * que el ViewModel no decide ninguna regla: recorta textos para el listado, traduce las fechas con
 * `FechaClinica` (que tolera `0000-00-00` y basura) y dice cuál es el estado vacío de cada bloque.
 *
 * El ViewModel se prueba **sin base de datos** (`tests/Unit/Clinica/`): todo lo que necesita llega por el
 * constructor ya leído, y las URLs las resuelve el controlador (mismo criterio que la ficha).
 */
final class HistoriaDePacienteVista
{
    /**
     * Cuánto texto entra en una fila del listado. El resumen de una consulta es un dato clave para
     * reconocerla, no el documento: lo que no entra se recorta con `…` y se lee completo en la impresión.
     */
    private const RECORTE = 160;

    public function __construct(
        private object $ficha,
        private Collection $consultas,
        private Collection $antecedentes,
        private Collection $documentos,
        private ?string $urlFicha = null,
        private ?string $urlImprimir = null,
        /**
         * `fn(int $consultaId, int $numero): string` — la hoja impresa de un récipe (WEB-5.2), o `null` si quien mira
         * no puede entrar a la consulta (la secretaría imprime desde la agenda): sin esto la fila no lleva enlace.
         */
        private ?Closure $urlImprimirRecipe = null,
        /**
         * `fn(int $consultaId, string $clave): string` — la hoja impresa de una constancia, un reposo, una referencia o un
         * informe (WEB-5.3a), o `null` si quien mira no puede entrar a la consulta. Misma razón que el de los récipes.
         */
        private ?Closure $urlImprimirDocumento = null,
    ) {
    }

    public function id(): int
    {
        return (int) $this->ficha->id;
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->ficha->apellidos, $this->ficha->nombres);
    }

    public function numeroDeHistoria(): ?string
    {
        return CamposDePaciente::numeroDeHistoria($this->ficha->numhistoria);
    }

    public function tieneHistoria(): bool
    {
        return $this->numeroDeHistoria() !== null;
    }

    /** `Historia N.º 1001 · 3 consultas` — o el aviso de que todavía no hay número. */
    public function subtitulo(): string
    {
        if (! $this->tieneHistoria()) {
            return 'Todavía sin número de historia';
        }

        return 'Historia N.º ' . $this->numeroDeHistoria() . ' · ' . $this->cuenta($this->totalConsultas(), 'consulta');
    }

    public function urlFicha(): ?string
    {
        return $this->urlFicha;
    }

    /**
     * La URL de la impresión del resumen. La resuelve el controlador (el ViewModel no llama a `route()`).
     */
    public function urlImprimir(): ?string
    {
        return $this->urlImprimir;
    }

    /**
     * Si la pantalla ofrece imprimir. Sin historia no hay resumen que imprimir; y sin URL no hay acción
     * que ofrecer.
     */
    public function puedeImprimir(): bool
    {
        return $this->tieneHistoria() && $this->urlImprimir !== null && $this->totalConsultas() > 0;
    }

    /**
     * El estado vacío de un paciente **sin historia**: no es que le falten datos, es que todavía no tiene
     * número, y el camino es asignárselo desde su ficha (WEB-3.3). Va como bloque propio de la pantalla,
     * porque sin historia los tres bloques de abajo no tienen nada que mostrar.
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function sinHistoria(): array
    {
        return [
            'icono'  => 'bi-journal-medical',
            'titulo' => 'Este paciente todavía no tiene historia',
            'texto'  => 'Sin número de historia no hay consultas, antecedentes ni documentos que mostrar. '
                . 'Se lo asigna el servidor con el correlativo de este consultorio desde la ficha del paciente.',
        ];
    }

    // ------------------------------------------------------------------------------------ Consultas

    public function totalConsultas(): int
    {
        return $this->consultas->count();
    }

    /**
     * Las consultas para el listado, la más reciente primero (lo ordena la lectura), con los motivos, la
     * enfermedad actual, el diagnóstico y la evolución ya recortados.
     *
     * @return array<int, array{fecha:string, numero:string, motivos:array<int,string>, enfermedad:?string, diagnostico:?string, evolucion:?string}>
     */
    public function consultas(): array
    {
        return $this->consultas->map(fn ($consulta) => [
            'fecha'       => FechaClinica::formato($consulta->fecha),
            'numero'      => 'Consulta ' . (int) $consulta->nroconsulta,
            'motivos'     => array_values((array) ($consulta->motivos ?? [])),
            'enfermedad'  => $this->recorte($consulta->enfermedadactual ?? null),
            'diagnostico' => $this->recorte(implode(' · ', (array) ($consulta->diagnosticos ?? [])) ?: null),
            'evolucion'   => $this->recorte($consulta->evolucion ?? null),
        ])->all();
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function sinConsultas(): array
    {
        return [
            'icono'  => 'bi-journal-text',
            'titulo' => 'Sin consultas todavía',
            'texto'  => 'Las consultas del paciente aparecen acá cuando se lo atiende.',
        ];
    }

    // --------------------------------------------------------------------------------- Antecedentes

    public function totalAntecedentes(): int
    {
        return $this->antecedentes->count();
    }

    /**
     * Los antecedentes **agrupados por tipo**, en el orden en que la lectura los trae (por código de tipo,
     * que es el orden del catálogo). El título del grupo es la descripción del tipo cuando el catálogo la
     * tiene; lo que no se puede clasificar cae en un último grupo `Sin clasificar`, que es una etiqueta de
     * la pantalla y **no** un tipo inventado.
     *
     * La marca de **alerta** sale de `antece_paciente.tipo2 = 'A'`, que es lo único que la fuente del
     * legado lee como alerta (`HistoriaDelPaciente::TIPO2_ALERTA`): se muestra con tono, icono y texto
     * propios, nunca sólo con color (guía de UI §1).
     *
     * @return array<int, array{titulo:string, filas:array<int, array{nombre:?string, detalle:?string, alerta:bool, alertaTitulo:string}>}>
     */
    public function antecedentes(): array
    {
        $grupos = [];

        foreach ($this->antecedentes as $antecedente) {
            $titulo = CamposDePaciente::texto($antecedente->tipoNombre ?? null) ?? 'Sin clasificar';

            $grupos[$titulo] ??= ['titulo' => $titulo, 'filas' => []];
            $grupos[$titulo]['filas'][] = [
                'nombre'       => CamposDePaciente::texto($antecedente->nombre ?? null),
                'detalle'      => CamposDePaciente::texto($antecedente->detalle ?? null),
                'alerta'       => (bool) ($antecedente->alerta ?? false),
                'alertaTitulo' => 'Marcado como alerta para las consultas de este paciente',
            ];
        }

        // "Sin clasificar" al final: los grupos del catálogo primero, en su orden.
        uksort($grupos, fn ($a, $b) => ($a === 'Sin clasificar' ? 1 : 0) <=> ($b === 'Sin clasificar' ? 1 : 0));

        return array_values($grupos);
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function sinAntecedentes(): array
    {
        return [
            'icono'  => 'bi-clipboard2-pulse',
            'titulo' => 'Sin antecedentes cargados',
            'texto'  => 'Los antecedentes se cargan en la consulta del paciente.',
        ];
    }

    // ----------------------------------------------------------------------------------- Documentos

    public function totalDocumentos(): int
    {
        return $this->documentos->count();
    }

    /**
     * Los documentos de la historia, el más reciente primero, con su tipo, su consulta y un resumen del
     * contenido. **No se crean acá** (F5): esta pantalla sólo los lista.
     *
     * @return array<int, array{tipo:string, icono:string, consulta:?string, fecha:string, resumen:?string, urlImprimir:?string}>
     */
    public function documentos(): array
    {
        $consultaPorNumero = $this->consultas->mapWithKeys(fn ($consulta) => [(int) $consulta->nroconsulta => (int) $consulta->id]);

        return $this->documentos->map(fn ($documento) => [
            'tipo'     => $documento['tipo'],
            'icono'    => $documento['icono'],
            'consulta' => isset($documento['consulta']) ? 'Consulta ' . (int) $documento['consulta'] : null,
            'fecha'    => FechaClinica::formato($documento['fecha'] ?? null),
            'resumen'  => $this->recorte($documento['resumen'] ?? null),
            // El enlace a la hoja impresa, sólo para quien puede abrirla (WEB-5.2 los récipes, WEB-5.3a el resto).
            'urlImprimir' => $this->urlDeLaHoja($documento, $consultaPorNumero),
        ])->all();
    }

    /**
     * La URL de la hoja impresa de un documento, o `null` si quien mira no puede abrirla (la secretaría imprime desde la
     * agenda) o el documento no tiene hoja.
     *
     * @param  array<string,mixed>  $documento
     * @param  Collection<int,int>  $consultaPorNumero  `nroconsulta => id de la consulta`
     */
    private function urlDeLaHoja(array $documento, Collection $consultaPorNumero): ?string
    {
        $consulta = $consultaPorNumero[(int) ($documento['consulta'] ?? 0)] ?? null;

        if ($consulta === null) {
            return null;
        }

        if (($documento['recipe'] ?? null) !== null) {
            return $this->urlImprimirRecipe === null ? null : ($this->urlImprimirRecipe)($consulta, (int) $documento['recipe']);
        }

        if (($documento['clave'] ?? null) !== null && $this->urlImprimirDocumento !== null) {
            return ($this->urlImprimirDocumento)($consulta, (string) $documento['clave']);
        }

        return null;
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function sinDocumentos(): array
    {
        return [
            'icono'  => 'bi-folder2-open',
            'titulo' => 'Sin documentos',
            'texto'  => 'Las constancias, reposos, referencias, informes y récipes del paciente aparecen acá.',
        ];
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * El texto de una fila: espacios colapsados y, si no entra, cortado con `…`. Es lo que hace que una
     * enfermedad actual de tres párrafos se lea como un dato clave y no como la consulta entera.
     */
    private function recorte(?string $texto): ?string
    {
        $limpio = CamposDePaciente::texto(preg_replace('/\s+/u', ' ', (string) $texto));

        if ($limpio === null || mb_strlen($limpio) <= self::RECORTE) {
            return $limpio;
        }

        return rtrim(mb_substr($limpio, 0, self::RECORTE), " \t\n\r\0\x0B.,;:") . '…';
    }

    private function cuenta(int $total, string $singular): string
    {
        return $total === 1 ? '1 ' . $singular : $total . ' ' . $singular . 's';
    }
}
