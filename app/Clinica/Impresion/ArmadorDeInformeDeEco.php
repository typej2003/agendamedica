<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\EstudioEcografico;
use App\Support\FechaClinica;

/**
 * **Arma el informe de una ecografía a hoja completa** (WEB-11.13) a partir del **mapa del dato** del estudio (`grupos()`), que es el mismo
 * que pinta el formulario: un campo nuevo del estudio sale solo en el informe.
 *
 * Reglas de lo que se imprime (las del escritorio, `d_eco_*_print` y la captura del Visualizar):
 *
 *  - **Todas las opciones**, con un círculo marcado en la elegida, cuando el campo tiene pocas (hasta `MAXIMO_DE_OPCIONES`); un
 *    desplegable largo (la gesta en romanos) sólo dice su valor.
 *  - Los **números** con su unidad y sus casilleros **aunque estén vacíos** (se lee como un formulario lleno, no como un texto corrido).
 *  - Los **textos largos** (`area`) con su rótulo arriba.
 *  - La **grilla de cuatro vasos** del Doppler como tabla.
 *  - La **ficha del embarazo** (gesta, FUR, PARA) va en el encabezado, no repetida en los bloques; la FPP y la EG ya vienen del documento base.
 *
 * Función pura, sin base de datos: la usan la web hoy y el móvil después.
 */
final class ArmadorDeInformeDeEco
{
    /** Hasta cuántas opciones se imprimen todas (con la elegida marcada); más que eso, sólo el valor. */
    public const MAXIMO_DE_OPCIONES = 6;

    /** Las columnas de la ficha: van al encabezado y no se repiten en los bloques. */
    private const FICHA = ['gesta', 'fur', 'para'];

    /**
     * @param  array<int,array<string,mixed>>  $grupos  el `grupos()` del estudio
     * @param  array<int,array{etiqueta:string, valor:string}>  $fijosDelDocumento  los datos cortos que ya trae el documento (EG, FPP…)
     * @param  string|null  $textoLibre  el informe en texto libre que dejó el modo «Plantillas» del escritorio (`puro_texto`): se imprime tal cual
     */
    public static function armar(EstudioEcografico $estudio, array $grupos, array $fijosDelDocumento = [], ?string $textoLibre = null): InformeDeEco
    {
        $ficha = [];
        $bloques = [];

        // Lo que el documento ya resolvió con más contexto que el mapa (el médico referido con su nombre, la menarquia con su unidad):
        // si un campo se llama igual que un dato corto del documento, se imprime ese valor.
        $resueltos = [];
        foreach ($fijosDelDocumento as $dato) {
            $resueltos[mb_strtolower(rtrim($dato['etiqueta'], ':'))] = $dato['valor'];
        }
        $usados = [];

        foreach ($grupos as $grupo) {
            if (($grupo['grilla'] ?? false) === true) {
                $bloques[] = ['titulo' => (string) $grupo['titulo'], 'campos' => [], 'grilla' => self::grilla($estudio, $grupo['campos'])];

                continue;
            }

            $campos = [];

            foreach ($grupo['campos'] as $campo) {
                if (in_array(strtolower((string) $campo['columna']), self::FICHA, true)) {
                    $valor = self::textoDelValor($estudio, $campo);

                    if ($valor !== null) {
                        $ficha[] = ['etiqueta' => rtrim((string) $campo['etiqueta'], ':') . ':', 'valor' => $valor];
                    }

                    continue;
                }

                // La fecha del estudio es la de la cabecera de la hoja.
                if ($campo['columna'] === 'fecha') {
                    continue;
                }

                $clave = mb_strtolower(rtrim((string) ($campo['etiqueta'] ?? ''), ':'));
                if ($clave !== '' && isset($resueltos[$clave])) {
                    $usados[$clave] = true;
                    $campos[] = self::resultado(rtrim((string) $campo['etiqueta'], ':'), 'valor', $resueltos[$clave], null, []);

                    continue;
                }

                $campos[] = self::campo($estudio, $campo);
            }

            // Un bloque de un solo campo que se llama como el bloque (Anexos, Fondo de saco de Douglas…) no repite el rótulo.
            if (count($campos) === 1 && mb_strtolower($campos[0]['etiqueta']) === mb_strtolower((string) $grupo['titulo'])) {
                $campos[0]['etiqueta'] = '';
            }

            if ($campos !== []) {
                $bloques[] = ['titulo' => (string) $grupo['titulo'], 'campos' => $campos, 'grilla' => null];
            }
        }

        // Los datos cortos del documento (EG, FPP, «Estudio del:») que la ficha no repite.
        $rotulos = array_map(fn (array $dato) => strtolower($dato['etiqueta']), $ficha);
        foreach ($fijosDelDocumento as $dato) {
            if (isset($usados[mb_strtolower(rtrim($dato['etiqueta'], ':'))])) {
                continue;
            }

            if (! in_array(strtolower($dato['etiqueta']), $rotulos, true)) {
                $ficha[] = $dato;
            }
        }

        if ($textoLibre !== null && trim($textoLibre) !== '') {
            $bloques[] = ['titulo' => 'Informe en texto libre', 'grilla' => null, 'campos' => [self::resultado('', 'area', trim($textoLibre), null, [])]];
        }

        return new InformeDeEco($ficha, $bloques);
    }

    /** @param  array<string,mixed>  $campo */
    private static function campo(EstudioEcografico $estudio, array $campo): array
    {
        $tipo = (string) ($campo['tipo'] ?? 'texto');
        $etiqueta = rtrim((string) ($campo['etiqueta'] ?? ''), ':');

        if ($tipo === 'opciones') {
            $opciones = $campo['opciones'] ?? [];
            $crudo = $estudio->valor($campo['columna']);

            if (count($opciones) > self::MAXIMO_DE_OPCIONES) {
                return self::resultado($etiqueta, 'valor', self::textoDelValor($estudio, $campo), null, []);
            }

            return self::resultado($etiqueta, 'opciones', null, null, array_map(
                fn (array $opcion) => ['texto' => (string) $opcion['texto'], 'marcada' => $crudo !== null && (string) $opcion['valor'] === $crudo],
                $opciones,
            ));
        }

        $valor = self::textoDelValor($estudio, $campo);

        // El sufijo del escritorio («(Percentil)» de la medida y el peso fetal del ecocardiograma) va después del valor.
        if ($valor !== null && ! empty($campo['sufijo'])) {
            $valor .= ' ' . $campo['sufijo'];
        }

        return self::resultado($etiqueta, $tipo === 'area' ? 'area' : 'valor', $valor, $tipo === 'numero' ? ($campo['unidad'] ?? null) : null, []);
    }

    /** @return array<string,mixed> */
    private static function resultado(string $etiqueta, string $tipo, ?string $valor, ?string $unidad, array $opciones): array
    {
        return ['etiqueta' => $etiqueta, 'tipo' => $tipo, 'valor' => $valor, 'unidad' => $unidad, 'opciones' => $opciones];
    }

    /**
     * El valor de un campo como se lee en la hoja: la fecha formateada, la opción por su texto, lo demás tal cual; `null` si está vacío.
     *
     * @param  array<string,mixed>  $campo
     */
    private static function textoDelValor(EstudioEcografico $estudio, array $campo): ?string
    {
        $crudo = $estudio->valor($campo['columna']);

        if ($crudo === null || $crudo === '') {
            return null;
        }

        $tipo = (string) ($campo['tipo'] ?? 'texto');

        if ($tipo === 'fecha' || strtolower((string) $campo['columna']) === 'fur') {
            return FechaClinica::formato($crudo);
        }

        if ($tipo === 'opciones') {
            foreach ($campo['opciones'] ?? [] as $opcion) {
                if ((string) $opcion['valor'] === $crudo) {
                    return (string) $opcion['texto'];
                }
            }
        }

        return $crudo;
    }

    /**
     * La grilla (los cuatro vasos del Doppler): una fila por vaso y una columna por índice, en el orden del mapa.
     *
     * @param  array<int,array<string,mixed>>  $campos
     * @return array{ejes:array<int,string>, filas:array<int,array{etiqueta:string, celdas:array<int,?string>}>}
     */
    private static function grilla(EstudioEcografico $estudio, array $campos): array
    {
        $ejes = [];
        $filas = [];

        foreach ($campos as $campo) {
            $eje = (string) ($campo['eje'] ?? $campo['etiqueta']);
            $fila = (string) ($campo['fila'] ?? '');

            if (! in_array($eje, $ejes, true)) {
                $ejes[] = $eje;
            }

            $filas[$fila]['etiqueta'] = $fila;
            $filas[$fila]['celdas'][] = self::textoDelValor($estudio, $campo);
        }

        return ['ejes' => $ejes, 'filas' => array_values($filas)];
    }
}
