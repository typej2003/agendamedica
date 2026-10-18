<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EcoDoppler as EcoDopplerModelo;

/**
 * **El eco Doppler perinatal de una consulta** (WEB-6.3): su mapa del dato y su lectura.
 *
 * Es el cuarto tipo de la sección de estudio (ítem 5 del panel del legado, `TiposDeEstudio::DOPPLER`) y el
 * hermano de `EcoObstetrico` (WEB-6.2) y `EcoGinecologico` (WEB-6.1): comparte con ellos la forma —**una fila
 * por consulta**, el mismo objeto de valores `EstudioEcografico`, la misma regla de "esto es un documento o
 * no" (`QueEsUnDocumento::ecografia`) y los mismos helpers del mapa (`MapaDelEcoObstetrico`, que a pesar del
 * nombre son genéricos: `texto`, `numero`, `lista`, `siNo`)— y se diferencia en tres cosas:
 *
 *  - **La tabla y su clave**: `eco_doppler`, llaveada por **`historia` + `consulta`** —no
 *    `numhistoria`/`nroconsulta` como el eco pélvico y los obstétricos—. Es la clave del DataWindow
 *    (`d_eco_doppler.srd:6-7`, las dos `key=yes`).
 *  - **La grilla de cuatro vasos**: 16 campos de **texto** (el escritorio guarda los milímetros como texto),
 *    cuatro arterias por cuatro columnas. Ver `grilla()`.
 *  - **La ficha** (`fur`, `gesta`, `para`) es del embarazo que se está siguiendo y **no cuenta** como
 *    hallazgo del estudio: `COLUMNAS_QUE_NO_CUENTAN`.
 *
 * ⚠️ **El DataWindow vivo no está donde parece** (trampas 102, 123 y 124): `d_eco_doppler` **no** está en
 * `datawin_procesos/` sino en `fuentes/ventana_consulta/d_eco_doppler.srd`, y es el que la pestaña viva de la
 * consulta lee y guarda (`w_consulta_principal.srw:22650`, `retrieve` en `:4716-4717` y guardado en
 * `:22543`). Acá sí la tabla se llama como el DataWindow.
 *
 * ⚠️ **`fecha` no se puede cargar desde ninguna pantalla** del escritorio (no tiene control en el
 * DataWindow): la pone el código al insertar la fila. La web la propone con **hoy** y deja editarla, como el
 * eco obstétrico.
 *
 * ⚠️ **`puro_texto`** es el informe en texto libre del modo «Plantillas» del escritorio: se **muestra y no
 * se edita** (igual que el eco pélvico de WEB-6.1; ver `COLUMNA_PURO_TEXTO`).
 */
final class EcoDoppler
{
    use MapaDelEcoObstetrico;

    /** La tabla del eco Doppler perinatal. */
    public const TABLA = 'eco_doppler';

    /** La columna de la historia, como en el DataWindow (`key=yes`): **es `historia`**, no `numhistoria`. */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /**
     * El informe en texto libre del escritorio (su modo «Plantillas»): se muestra y **no** se edita.
     */
    public const COLUMNA_PURO_TEXTO = 'puro_texto';

    /**
     * Las columnas de la **ficha** que no cuentan para decidir si el estudio dice algo: son del embarazo que
     * se está siguiendo (o del encabezado del informe), no hallazgos del estudio. Es la lista que comparten
     * la Action (`GuardarEcoDoppler`), la pantalla (`EcoDopplerVista`) y la impresión.
     *
     * `puro_texto` **sí** cuenta: un estudio sin campos pero con el informe en texto libre del escritorio es
     * un documento (es lo que se imprime), como en el eco pélvico.
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_CUENTAN = ['fecha', 'fur', 'gesta', 'para'];

    /**
     * **El mapa del dato del eco Doppler**, en los cuatro bloques del formulario.
     *
     * Los rótulos son los del DataWindow vivo y los `códigos` de cada lista, los de sus `values`. Dos avisos:
     *
     *  - La **grilla de cuatro vasos** va como un bloque con `grilla => true`: cada uno de sus 16 campos dice
     *    su `fila` (la arteria) y su `eje` (la columna), que es lo que la pantalla y la hoja usan para
     *    armarla. Las cuatro columnas del DataWindow son *Índice de Pulsatibilidad* (`_menor`),
     *    *Índice de Resistencia* (`_mayor`), *Relación S / D* (`relacion_*`) y *Pico de Velocidad Sistólica*
     *    (`pico_*`).
     *  - **Los códigos de `ven_umbilical` no son los que uno esperaría**: el `values` del DataWindow es
     *    `Si N/No A/`, o sea **Sí guarda `N` y No guarda `A`**. Se respeta lo que guarda el escritorio; el
     *    texto que ve el médico es *Sí* o *No* (su formato en blanco lo rotula *Latidos Normales* o
     *    *Anormales* para los mismos códigos: ver `FormatosEnBlanco`).
     *
     * @return array<int,array{titulo:string, icono:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        return [
            ['titulo' => 'Ficha', 'icono' => 'bi-calendar-heart', 'campos' => [
                self::fecha('fecha', 'Fecha',
                    'La fecha del estudio: abre con la de hoy y se puede cambiar.'),
                self::fechaTecleada('fur', 'FUR', 'La fecha de la última regla: se teclea como dd/mm/aaaa.'),
                ['columna' => 'gesta', 'etiqueta' => 'Gesta', 'tipo' => 'opciones',
                    'opciones' => self::romanos(), 'ayuda' => 'El número de la gesta que se está siguiendo.'],
                // `para` es `char(10)`: el rótulo parece pedir un número, pero el escritorio la guarda como
                // texto (ahí entran cosas como «1 cesárea»).
                ['columna' => 'para', 'etiqueta' => 'Para', 'tipo' => 'texto', 'tope' => 10,
                    'ayuda' => 'La paridad, como la escriba el médico: el escritorio la guarda como texto.'],
            ]],

            ['titulo' => 'Eco Doppler Obstétrico en 4 Vasos', 'icono' => 'bi-table', 'grilla' => true, 'campos' => [
                self::celda('arteria_uraina_menor', 'Arteria Uterina Der.', 'Índice de Pulsatibilidad'),
                self::celda('arteria_uraina_mayor', 'Arteria Uterina Der.', 'Índice de Resistencia'),
                self::celda('relacion_ud', 'Arteria Uterina Der.', 'Relación S / D'),
                self::celda('pico_ud', 'Arteria Uterina Der.', 'Pico de Velocidad Sistólica'),

                self::celda('arteria_uraina_menor_izq', 'Arteria Uterina Izq.', 'Índice de Pulsatibilidad'),
                self::celda('arteria_uraina_mayor_izq', 'Arteria Uterina Izq.', 'Índice de Resistencia'),
                self::celda('relacion_ui', 'Arteria Uterina Izq.', 'Relación S / D'),
                self::celda('pico_ui', 'Arteria Uterina Izq.', 'Pico de Velocidad Sistólica'),

                self::celda('arteria_umbilical_menor', 'Arteria Umbilical', 'Índice de Pulsatibilidad'),
                self::celda('arteria_umbilical_mayor', 'Arteria Umbilical', 'Índice de Resistencia'),
                self::celda('relacion_u', 'Arteria Umbilical', 'Relación S / D'),
                self::celda('pico_u', 'Arteria Umbilical', 'Pico de Velocidad Sistólica'),

                self::celda('arteria_cerebral_menor', 'Arteria Cerebral Media', 'Índice de Pulsatibilidad'),
                self::celda('arteria_cerebral_mayor', 'Arteria Cerebral Media', 'Índice de Resistencia'),
                self::celda('relacion_cm', 'Arteria Cerebral Media', 'Relación S / D'),
                self::celda('pico_cm', 'Arteria Cerebral Media', 'Pico de Velocidad Sistólica'),
            ]],

            ['titulo' => 'El flujo venoso y la aorta', 'icono' => 'bi-droplet-half', 'campos' => [
                // La errata del escritorio («Ductos Venosos») se corrigió a pedido de una médica que probó la
                // web (2026-10-17): el vaso es el **ductus venoso**, en singular. La columna se sigue llamando
                // `ductos_venosos` (es el nombre del dato y del `formato_print` del legado) y la hoja impresa
                // sale de este mismo mapa, así que también dice «Ductus venoso».
                self::lista('ductos_venosos', 'Ductus venoso',
                    ['Trifásico con Reversa' => 'N', 'Trifásico sin Reversa' => 'S'],
                    'El rótulo del escritorio decía «Ductos»; en la web es «Ductus venoso» (errata del '
                    . 'escritorio corregida el 2026-10-17).'),
                self::lista('ven_umbilical', 'Señal Venoso Umbilical (Pulsatilidad)',
                    ['Sí' => 'N', 'No' => 'A'],
                    'Ojo con los códigos: **Sí guarda `N` y No guarda `A`**, al revés de lo que parece '
                    . '(su formato en blanco los rotula Latidos Normales/Anormales).'),
                self::lista('relac_aorta', 'Relación Aorta-Cava', ['Normal' => 'N', 'Anormal' => 'A']),
            ]],

            ['titulo' => 'Las observaciones y la conclusión', 'icono' => 'bi-file-earmark-text', 'campos' => [
                // El memo grande del estudio: el DataWindow **no le pone rótulo**, así que la pantalla lo
                // pinta sin etiqueta visible (con un nombre accesible para el campo).
                ['columna' => 'texto', 'etiqueta' => '', 'tipo' => 'area',
                    'ayuda' => 'El informe del estudio, en texto libre.'],
                ['columna' => 'sugerencias', 'etiqueta' => 'Observaciones', 'tipo' => 'area',
                    'ayuda' => 'El rótulo de la pantalla es «Observaciones:» (la columna se llama '
                        . '`sugerencias`); en la hoja se imprime «Sugerencias:».'],
                ['columna' => 'conclusion', 'etiqueta' => 'Conclusiones', 'tipo' => 'area',
                    'ayuda' => 'El rótulo de la pantalla es «Conclusiones:»; en la hoja se imprime '
                        . '«Conclusión:», en singular, como el DataWindow de impresión.'],
            ]],
        ];
    }

    /**
     * **La grilla de cuatro vasos**, agrupada por fila: las cuatro arterias y, en cada una, sus cuatro
     * celdas. Es lo que la hoja imprime con el rótulo del bloque; sale del mapa, así que una celda nueva del
     * DataWindow entra sola.
     *
     * @return array<int,array{etiqueta:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grilla(): array
    {
        foreach (self::grupos() as $grupo) {
            if (($grupo['grilla'] ?? false) !== true) {
                continue;
            }

            $filas = [];
            foreach ($grupo['campos'] as $campo) {
                $filas[$campo['fila']]['etiqueta'] = $campo['fila'];
                $filas[$campo['fila']]['campos'][] = $campo;
            }

            return array_values($filas);
        }

        return [];
    }

    /**
     * Los **ejes** de la grilla (sus cuatro columnas), en el orden del DataWindow: son los mismos en las
     * cuatro filas.
     *
     * @return array<int,string>
     */
    public static function ejesDeLaGrilla(): array
    {
        foreach (self::grilla() as $fila) {
            return array_column($fila['campos'], 'eje');
        }

        return [];
    }

    /**
     * Las columnas que la pantalla edita, en el orden del mapa: es lo que la Action guarda.
     *
     * @return array<int,string>
     */
    public static function columnas(): array
    {
        $columnas = [];

        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $columnas[] = $campo['columna'];
            }
        }

        return $columnas;
    }

    /** El rótulo de pantalla de una columna, o la columna si no está en el mapa. */
    public static function etiquetaDe(string $columna): string
    {
        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                if ($campo['columna'] === $columna) {
                    return (string) $campo['etiqueta'];
                }
            }
        }

        return $columna;
    }

    /**
     * Las opciones de una columna con lista, del mapa (`[]` si esa columna no es un desplegable).
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    public static function opcionesDe(string $columna): array
    {
        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                if ($campo['columna'] === $columna && ($campo['tipo'] ?? null) === 'opciones') {
                    return $campo['opciones'];
                }
            }
        }

        return [];
    }

    /**
     * El texto que se muestra para un valor guardado: el rótulo de la opción, o **el valor tal cual** si no
     * está en la lista del DataWindow (los datos del escritorio pueden traer cualquier código). Es la misma
     * regla que usa el eco pélvico (`EcoGinecologico::textoDe`).
     */
    public static function textoDe(string $columna, $valor): ?string
    {
        $limpio = EstudioEcografico::limpiar($valor);

        if ($limpio === null) {
            return null;
        }

        foreach (self::opcionesDe($columna) as $opcion) {
            if ($opcion['valor'] === $limpio) {
                return $opcion['texto'];
            }
        }

        return $limpio;
    }

    /**
     * El estudio de una consulta: sus valores, normalizados, o el objeto **vacío** si esa consulta no tiene
     * eco Doppler (la pantalla lo muestra en blanco y la hoja no se ofrece).
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * La fila del eco Doppler de una consulta, o `null`.
     *
     * Se busca por la clave exacta del DataWindow —`reg_medico` (del contexto) + **`historia`** +
     * **`consulta`**—: una fila por consulta.
     */
    public function filaDe(Consulta $consulta): ?EcoDopplerModelo
    {
        return EcoDopplerModelo::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila del eco de una consulta **para escribir**: la que ya existe o una nueva con su clave puesta
     * (`reg_medico`, `historia` y `consulta`) para que la Action sólo tenga que completar lo que el médico
     * cargó. La clave la arma el mapa del dato y no el formulario.
     */
    public function filaParaEscribir(Consulta $consulta): EcoDopplerModelo
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoDopplerModelo([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }

    /**
     * Una celda de la grilla de cuatro vasos: su columna, la arteria (su `fila`) y el eje (su columna de la
     * grilla). El escritorio guarda los milímetros como **texto** (`char(20)`).
     */
    private static function celda(string $columna, string $fila, string $eje): array
    {
        return [
            'columna' => $columna,
            'etiqueta' => $fila . ': ' . $eje,
            'tipo' => 'texto',
            'tope' => 20,
            'fila' => $fila,
            'eje' => $eje,
            'ayuda' => 'El escritorio guarda los milímetros como texto.',
        ];
    }

    /**
     * Las opciones del desplegable de la gesta: los números romanos **I a XII** con el código `1..12` que
     * guarda el escritorio (`editmask.spin` con máscara `##`).
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    private static function romanos(): array
    {
        $romanos = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

        $opciones = [];
        foreach ($romanos as $posicion => $romano) {
            $opciones[] = ['valor' => (string) ($posicion + 1), 'texto' => $romano];
        }

        return $opciones;
    }
}
