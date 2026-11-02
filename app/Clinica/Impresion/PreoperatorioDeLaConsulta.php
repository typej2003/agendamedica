<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\ExamenesDeLaConsulta;
use App\Clinica\Consulta\Interna\DatosDelInternista;
use App\Clinica\Consulta\Interna\MapaDelInternista;
use App\Clinica\Consulta\IntervencionesDeLaConsulta;
use App\Clinica\Consulta\RiesgoQuirurgico;
use App\Models\Consulta;

/**
 * **Arma el informe de evaluación pre-operatoria** (O-47) con lo que la consulta tiene cargado.
 *
 * Las fuentes son las doce del registro y **cada una se lee de donde ya vive**, sin copiar datos:
 *
 *  1. **Antecedentes** — `antece_paciente`, agrupados por tipo (`AntecedentesDelPaciente`).
 *  2. **Enfermedad actual** — `consultas.enfermedadactual`.
 *  3. **Resumen de examen físico** — `consultas.resultadoexamencomp` (el «Actualizar» del resumen).
 *  4. **Laboratorio** — `examen_paciente` con **`vinculante = 'S'`** y su `resultado`. **No** son los quince
 *     números de *Plan de diagnóstico*, que alimentan las gráficas del análisis evolutivo (O-48).
 *  5. **E.K.G.** — `ekg.conclusiones`.
 *  6. **R.X. Tórax** — `consultas.faringe`.
 *  7. **Diagnóstico** — `diagnostico_paciente` (`DiagnosticosDeLaConsulta`).
 *  8. **Escalas** — `pre_espiro`, `pre_trombo`, `pre_infec`, `pre_espec` y `pre_aneste`: se imprime el
 *     `resultado` que el médico vio y pudo corregir y, si está vacío, el texto del catálogo
 *     (`MapaDelInternista::textoCompletoDeLaOpcion`, con el marcador de la emergencia resuelto).
 *  9. **Riesgo, intervención y puntos** — `riesgo_paciente_opera` y `interven_consulta`.
 * 10. **Recomendaciones** — `riesgo_paciente_opera.recomendaciones`.
 *
 * Queda **fuera** el «REFERIDO POR» que muestra la hoja del escritorio: no está entre las fuentes del pedido y
 * `consultas.referido` es un código de médico del catálogo, así que no se adivina el nombre desde acá.
 */
final class PreoperatorioDeLaConsulta
{
    /** Las escalas de la segunda página, en el orden del escritorio: tabla => título impreso. */
    private const ESCALAS = [
        'pre_espiro' => 'ESPIROMETRÍA',
        'pre_trombo' => 'RIESGO DE TROMBOSIS VENOSA PROFUNDA Y TROMBOEMBOLISMO PULMONAR',
        'pre_infec' => 'RIESGO DE INFECCIONES DE HERIDAS OPERATORIAS',
        'pre_espec' => 'SITUACIONES ESPECIALES',
        'pre_aneste' => 'CLASIFICACIÓN DEL ESTADO FÍSICO SEGÚN LA ASOCIACIÓN NORTEAMERICANA DE ANESTESIÓLOGOS',
    ];

    public function de(Consulta $consulta): InformePreoperatorio
    {
        $datos = new DatosDelInternista();
        $estimaciones = $datos->valores($consulta, 'estimaciones');

        return new InformePreoperatorio(
            encabezado: $this->encabezado($consulta),
            bloques: $this->bloques($consulta, $datos),
            escalas: $this->escalas($estimaciones),
            // Las recomendaciones no están en el resumen del riesgo (`CAMPOS_RESUMEN`): se leen de la fila.
            recomendaciones: $this->texto((new RiesgoQuirurgico())->resumen($consulta)?->recomendaciones),
            riesgo: $this->riesgo($consulta),
        );
    }

    /**
     * Los datos cortos de arriba: la intervención y el diagnóstico. Van juntos porque son los dos que la hoja
     * imprime antes de los antecedentes.
     *
     * @return array<int,array{etiqueta:string, valor:string}>
     */
    private function encabezado(Consulta $consulta): array
    {
        $datos = [];

        $intervencion = $this->texto((new RiesgoQuirurgico())->de($consulta)['nombre_intervencion'] ?? null)
            ?? $this->intervenciones($consulta);

        if ($intervencion !== null) {
            $datos[] = ['etiqueta' => 'INTERVENCIÓN', 'valor' => $intervencion];
        }

        $diagnosticos = (new DiagnosticosDeLaConsulta())
            ->deLaConsulta((string) $consulta->reg_medico, $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->map(fn ($fila) => $this->texto($fila->nombre))
            ->filter()
            ->values()
            ->all();

        if ($diagnosticos !== []) {
            $datos[] = ['etiqueta' => 'DIAGNÓSTICO', 'valor' => implode(' · ', $diagnosticos)];
        }

        return $datos;
    }

    /**
     * Los cuerpos de la primera página, en el orden de la hoja.
     *
     * @return array<int,array{titulo:string, tipo:string, texto?:?string, items?:array<int,array{nombre:string, resultado:?string}>}>
     */
    private function bloques(Consulta $consulta, DatosDelInternista $datos): array
    {
        $bloques = [];

        $antecedentes = $this->antecedentes($consulta);

        if ($antecedentes !== null) {
            $bloques[] = ['titulo' => 'ANTECEDENTES', 'tipo' => 'texto', 'texto' => $antecedentes];
        }

        foreach ([
            'ENFERMEDAD ACTUAL' => $consulta->enfermedadactual,
            'EXÁMEN FÍSICO' => $consulta->resultadoexamencomp,
        ] as $titulo => $texto) {
            $limpio = $this->texto($texto);

            if ($limpio !== null) {
                $bloques[] = ['titulo' => $titulo, 'tipo' => 'texto', 'texto' => $limpio];
            }
        }

        $laboratorio = $this->laboratorio($consulta);

        if ($laboratorio !== []) {
            $bloques[] = ['titulo' => 'LABORATORIO', 'tipo' => 'lista', 'items' => $laboratorio];
        }

        $ekg = $this->texto($datos->fila($consulta, 'ekg')?->getAttribute('conclusiones'));

        if ($ekg !== null) {
            $bloques[] = ['titulo' => 'E.K.G.', 'tipo' => 'texto', 'texto' => $ekg];
        }

        $rx = $this->texto($consulta->faringe);

        if ($rx !== null) {
            $bloques[] = ['titulo' => 'R.X. TÓRAX', 'tipo' => 'texto', 'texto' => $rx];
        }

        return $bloques;
    }

    /** Los antecedentes del paciente agrupados por tipo: «PERSONAL: ACORTAMIENTO · …». */
    private function antecedentes(Consulta $consulta): ?string
    {
        $filas = (new AntecedentesDelPaciente())->delPaciente((string) $consulta->reg_medico, $consulta->numhistoria);

        if ($filas->isEmpty()) {
            return null;
        }

        $porTipo = [];

        foreach ($filas as $fila) {
            $nombre = $this->texto($fila->nombre);

            if ($nombre === null) {
                continue;
            }

            $tipo = $this->texto($fila->tipoNombre) ?? 'SIN CLASIFICAR';
            $porTipo[$tipo][] = $nombre . ($this->texto($fila->detalle) !== null ? ' (' . $this->texto($fila->detalle) . ')' : '');
        }

        if ($porTipo === []) {
            return null;
        }

        $lineas = [];

        foreach ($porTipo as $tipo => $nombres) {
            $lineas[] = mb_strtoupper($tipo) . ': ' . implode(' · ', $nombres);
        }

        return implode("\n", $lineas);
    }

    /** El perfil preoperatorio: los exámenes marcados como «sale en el informe», con su resultado. */
    private function laboratorio(Consulta $consulta): array
    {
        return (new ExamenesDeLaConsulta())
            ->deLaConsulta((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->filter(fn ($fila) => mb_strtoupper(trim((string) $fila->vinculante)) === 'S')
            ->map(fn ($fila) => ['nombre' => $fila->nombre, 'resultado' => $this->texto($fila->resultado)])
            ->values()
            ->all();
    }

    /** Las intervenciones cargadas, si la consulta no tiene el nombre en el resumen del riesgo. */
    private function intervenciones(Consulta $consulta): ?string
    {
        $nombres = (new IntervencionesDeLaConsulta())->de($consulta)
            ->map(fn ($fila) => $this->texto($fila->descripcion))
            ->filter()
            ->values()
            ->all();

        return $nombres === [] ? null : implode(' · ', $nombres);
    }

    /**
     * Las cinco escalas de la segunda página, cada una con su rótulo corto y el texto de la opción elegida: el
     * que el médico corrigió, si lo hay, y si no el del catálogo.
     *
     * @param  array<string,?string>  $estimaciones
     * @return array<int,array{titulo:string, etiqueta:?string, texto:?string}>
     */
    private function escalas(array $estimaciones): array
    {
        $emergencia = ($estimaciones['pre_aneste_emergencia'] ?? null) === 'S';
        $escalas = [];

        foreach (self::ESCALAS as $tabla => $titulo) {
            $valor = $estimaciones[$tabla . '_tipo'] ?? null;
            $etiqueta = MapaDelInternista::textoDeOpcion($tabla . '_tipo', $valor);

            $texto = $this->texto($estimaciones[$tabla . '_resultado'] ?? null)
                ?? MapaDelInternista::textoCompletoDeLaOpcion($tabla . '_tipo', $valor);

            $texto = $texto === null ? null : $this->sinMarcadorDeEmergencia($texto, $emergencia);

            if ($etiqueta === null && $texto === null) {
                continue;
            }

            $escalas[] = ['titulo' => $titulo, 'etiqueta' => $etiqueta, 'texto' => $texto];
        }

        return $escalas;
    }

    /**
     * El renglón del cierre: «Clase I - Individuo Sano - Puntos: 3». Se arma con lo que haya: una consulta sin
     * clase imprime sólo los puntos, y al revés.
     */
    private function riesgo(Consulta $consulta): ?string
    {
        $riesgo = new RiesgoQuirurgico();
        $resumen = $riesgo->de($consulta);
        $partes = [];

        $clase = $this->texto($resumen['clase'] ?? null);
        $conclusion = $this->texto($resumen['conclusion'] ?? null);

        if ($clase !== null) {
            $partes[] = str_starts_with(mb_strtolower($clase), 'clase') ? $clase : 'Clase ' . $clase;
        }

        if ($conclusion !== null) {
            $partes[] = $conclusion;
        }

        $puntos = $riesgo->puntos($consulta);

        if ($puntos > 0) {
            $partes[] = 'Puntos: ' . $puntos;
        }

        return $partes === [] ? null : implode(' - ', $partes);
    }

    /** El `{emergencia}` del texto de la clase ASA: el escritorio interpola «en Emergencia, » según la casilla. */
    private function sinMarcadorDeEmergencia(string $texto, bool $emergencia): string
    {
        return str_replace('{emergencia}', $emergencia ? 'en Emergencia, ' : '', $texto);
    }

    /** Un texto del legado: `null` cuando sólo hay espacios o el literal `none` que escribe el DataWindow. */
    private function texto(?string $valor): ?string
    {
        $limpio = trim((string) $valor);

        if ($limpio === '' || mb_strtolower($limpio) === 'none') {
            return null;
        }

        return $limpio;
    }
}
