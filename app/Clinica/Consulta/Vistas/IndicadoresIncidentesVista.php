<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): el panel de números del ítem 5, con sus valores
 * guardados, y el resumen de laboratorio que se compone con ellos.
 *
 * Los números viven en `examenes_paciente_consulta` (una fila por consulta) y el texto en
 * `consultas.resultadoexamencomp`; el mapa está en `IndicadoresIncidentes`. La pantalla abre **con lo que ya está
 * guardado** y se puede corregir, como en el escritorio.
 *
 * El ViewModel no sabe de `Request` ni de base de datos: recibe los grupos y los valores ya leídos.
 */
final class IndicadoresIncidentesVista
{
    /**
     * @param  array<string,array<string,string>>  $grupos  grupo => [clave => etiqueta], en el orden del escritorio
     * @param  array<string,?string>  $valores  los valores guardados, por clave (más `fecha`)
     * @param  string|null  $guardado  el texto que hoy tiene `consultas.resultadoexamencomp`
     * @param  string  $urlGuardar  a dónde postea "Guardar"
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     * @param  array<string,mixed>  $old  lo escrito en un POST que falló la validación (no se borra, guía de UI §3)
     * @param  string  $pestana  `indicadores` o `renal`: la solapa activa de la sección
     * @param  EcoRenalVista|null  $ecoRenal  el eco renal, cuando la solapa activa es la suya (el escritorio lo abre
     *                                        desde el botón del panel de indicadores)
     */
    public function __construct(
        private array $grupos,
        private array $valores,
        private ?string $guardado,
        private string $urlGuardar,
        private string $urlVolver = '',
        private array $old = [],
        private string $pestana = self::INDICADORES,
        private ?EcoRenalVista $ecoRenal = null,
    ) {
    }

    public const INDICADORES = 'indicadores';
    public const RENAL = 'renal';

    public function pestana(): string
    {
        return $this->pestana;
    }

    public function esEcoRenal(): bool
    {
        return $this->pestana === self::RENAL;
    }

    public function ecoRenal(): ?EcoRenalVista
    {
        return $this->ecoRenal;
    }

    /** Las solapas de la sección, con su URL y si están activas. @return array<int,array{clave:string, texto:string, url:string, activa:bool}> */
    public function pestanas(): array
    {
        $url = fn (string $clave) => $this->urlVolver . (str_contains($this->urlVolver, '?') ? '&' : '?') . 'pestana=' . $clave;

        return [
            ['clave' => self::INDICADORES, 'texto' => 'Indicadores', 'url' => $url(self::INDICADORES), 'activa' => ! $this->esEcoRenal()],
            ['clave' => self::RENAL, 'texto' => 'Eco renal', 'url' => $url(self::RENAL), 'activa' => $this->esEcoRenal()],
        ];
    }

    /**
     * Los grupos del formulario con el valor de cada campo: `[['titulo', 'campos' => [['clave', 'etiqueta', 'valor'], …]], …]`.
     *
     * @return array<int,array{titulo:string, campos:array<int,array{clave:string, etiqueta:string, valor:string}>}>
     */
    public function grupos(): array
    {
        $salida = [];

        foreach ($this->grupos as $titulo => $campos) {
            $filas = [];

            foreach ($campos as $clave => $etiqueta) {
                $filas[] = [
                    'clave'    => $clave,
                    'etiqueta' => $etiqueta,
                    'valor'    => $this->valor($clave),
                ];
            }

            $salida[] = ['titulo' => $titulo, 'campos' => $filas];
        }

        return $salida;
    }

    /** El valor que muestra el campo: lo que se acaba de escribir (`old`) o lo guardado. */
    public function valor(string $clave): string
    {
        if (array_key_exists($clave, $this->old)) {
            return trim((string) $this->old[$clave]);
        }

        return (string) ($this->valores[$clave] ?? '');
    }

    /** La fecha del panel (la de la toma de laboratorio), o vacía. */
    public function fecha(): string
    {
        return $this->valor('fecha');
    }

    /** El resumen guardado, o `null` si la consulta todavía no tiene ninguno. */
    public function guardado(): ?string
    {
        return $this->guardado;
    }

    public function tieneGuardado(): bool
    {
        return $this->guardado !== null && $this->guardado !== '';
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** El estado como pastilla: "Cargado" si hay algún valor o un resumen, y "Sin cargar" si no. */
    public function estado(): array
    {
        $conDatos = $this->tieneGuardado()
            || array_filter($this->valores, fn ($valor) => $valor !== null && $valor !== '');

        return $conDatos
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado',
                'titulo' => 'La consulta ya tiene indicadores guardados']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay indicadores en esta consulta'];
    }

    /** La ayuda de la pantalla. */
    public function ayuda(): string
    {
        return 'Los valores se guardan en la consulta y se vuelven a mostrar cuando la abrís de nuevo. Al guardar se '
            . 'compone el resumen de laboratorio (urea, creatinina, electrolitos, hemograma y orina), que es lo que '
            . 'imprimen el resumen médico y la evaluación pre-operatoria. Los gases, el volumen urinario, el sodio '
            . 'urinario y la creatinina calculada se guardan, pero no entran en ese resumen.';
    }

    /** El aviso de cambios sin guardar (lo usa `consulta.js` por `data-aviso`). */
    public function avisoSinGuardar(): string
    {
        return 'Hay valores escritos sin guardar en los indicadores. Si sales ahora, se pierden.';
    }

    /** El marcador de cada campo: es un número, no texto libre. */
    public function marcador(): string
    {
        return '—';
    }
}
