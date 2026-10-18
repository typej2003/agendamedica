<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\UltraAbdominal as UltraAbdominalModelo;

/**
 * **El eco abdominal de una consulta** (WEB-6.4): su mapa del dato y su lectura.
 *
 * Es el **séptimo y último** tipo de la sección de estudio (ítem 5 del panel del legado,
 * `TiposDeEstudio::ABDOMINAL`) y el hermano de los otros ecos (WEB-6.1 a WEB-6.4): comparte con ellos la forma
 * —**una fila por consulta**, el mismo objeto de valores `EstudioEcografico`, la misma regla de "esto es un
 * documento o no" (`QueEsUnDocumento::ecografia`) y el mismo camino de guardado
 * (`GuardarEcoObstetricoBase`)—.
 *
 * ⚠️ **Es una ecografía abdominal general**, no un estudio gineco-obstétrico: la misma tabla
 * (`ultra_abdominal`) y el mismo DataWindow los usan cirugía y pediatría. Se implementa porque el ginecólogo
 * la cargó (57 filas del dump, todas de `gineco-00001`) y la pestaña viva de la consulta la tiene
 * (`w_consulta_principal.srw:23110`, `dw_67`).
 *
 * ⚠️ **El DataWindow no se llama como la tabla** (trampas 102, 123 y 124): el vivo es
 * **`d_ultasonido_abdominal`**, está en `fuentes/ventana_consulta/` y **no** en `datawin_procesos/`, y escribe
 * en `ultra_abdominal`. El estudio propio del escritorio es `w_cargar_visualizar_eco_abdominal`.
 *
 * Lo que lo distingue de los otros seis:
 *
 *  - **Cuatro desplegables con códigos** (los `values` del DataWindow): `vol_res_v_p_m` y `ascitis` son
 *    Sí/No con los códigos **`N`/`A`** —al revés de las casillas Sí/No de los obstétricos, que guardan
 *    `S`/`N`— y `retroperitoneo` y `vasos_sanguineos` son Normal/Anormal con **`N`/`A`**. Los cuatro se
 *    pintan con `OpcionesDelDesplegable` (la opción vacía adelante, trampa 112).
 *  - **El desplegable de médicos** (`referido`, que guarda la **cédula** de un colega del catálogo
 *    `doctores`): el mapa lo declara con sus `opciones` **vacías** y el ViewModel (`EcoAbdominalVista`) las
 *    completa con la libreta del médico (`DoctoresDelMedico::delCatalogo()`, la misma lectura sin tope de la
 *    búsqueda), como el eco mamario. No es un código del legado sino una clave ajena.
 *  - **La ficha es sólo la fecha** (el servidor la completa con hoy si inserta): `COLUMNAS_QUE_NO_CUENTAN`
 *    declara nada más la fecha, como el mamario. El transductor **sí** cuenta: es el encabezado del informe.
 *
 * ⚠️ **`prostata` y `vejiga_urina` son de urología y en ginecología van OCULTAS** en el formulario y en la
 * hoja del escritorio (`visible="0"`, ver `COLUMNAS_DE_UROLOGIA`): la web **no las ofrece ni las imprime**
 * —decisión del usuario (2026-10-14)— y **no las toca al guardar**, para no pisar lo que otro build haya
 * escrito. No entran en el mapa.
 *
 * `puro_texto` es el informe en texto libre del modo «Plantillas» del escritorio (sus plantillas son
 * `formato_print` con `tipo = 'UA'`, que la web **no** edita): se **muestra y no se edita** y **no se
 * imprime** en la hoja estructurada —ese modo es **otro** DataWindow de impresión,
 * `d_ultasonido_abdominal_print_puro`, que la web no imprime—, así que tampoco cuenta como un documento.
 *
 * Sus fotos van a `imagen_consulta` con `observacion = 'a'` (`TagDeImagen::ABDOMINAL`). La web **no** ofrece
 * subir imágenes desde esta sección, como en los otros ecos.
 */
final class EcoAbdominal
{
    /** La tabla del eco abdominal. */
    public const TABLA = 'ultra_abdominal';

    /** La columna de la historia, como en el DataWindow (`key=yes`): **es `historia`**, no `numhistoria`. */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /** La columna del **médico referente**: guarda su **cédula** del catálogo `doctores`. */
    public const COLUMNA_REFERIDO = 'referido';

    /**
     * El informe en texto libre del escritorio (su modo «Plantillas», `formato_print` tipo `UA`): se muestra
     * y **no** se edita ni se imprime en la hoja estructurada.
     */
    public const COLUMNA_PURO_TEXTO = 'puro_texto';

    /**
     * **Las dos columnas de urología** que en ginecología van ocultas en el escritorio (`visible="0"`): la web
     * no las ofrece ni las imprime y **no las toca al guardar**. Está acá para que la decisión tenga un solo
     * nombre —lo citan el docblock de la clase y los tests, que fijan que no estén en el mapa— (ver el
     * docblock de la clase).
     *
     * @var array<int,string>
     */
    public const COLUMNAS_DE_UROLOGIA = ['prostata', 'vejiga_urina'];

    /**
     * Las columnas de la **ficha** que no cuentan para decidir si el estudio dice algo: acá es **sólo la
     * fecha**, la que el servidor completa con hoy (igual que el mamario). Los dos textos cortos —el
     * transductor y el referido— **sí** cuentan: son el encabezado del informe.
     *
     * Es la lista que comparten la Action (`GuardarEcoAbdominal`), la pantalla (`EcoAbdominalVista`), la
     * impresión y la historia del paciente.
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_CUENTAN = ['fecha'];

    /**
     * **El mapa del dato del eco abdominal**, en los cuatro bloques del formulario y en el orden del
     * DataWindow vivo (el mismo del formulario y del informe: de arriba hacia abajo).
     *
     * Los rótulos son los del DataWindow (`Fecha:`, `Transductor:`, `Referido por:`, `Hígado:`,
     * `Vesícula biliar:`, `Porta y colédoco:`, `Páncreas:`, `Rinón derecho:`, `Rinón izquierdo:`,
     * `Volumen residual vesical Post-Micción:`, `Ascitis:`, `Retroperitoneo:`, `Vasos sanguíneos:`,
     * `Otros:` y `Conclusiones:`), sin los dos puntos que el escritorio escribe en su texto: la pantalla y la
     * hoja los agregan. ⚠️ El rótulo del área es **«Otros»** en el formulario y **«Observaciones»** en la
     * hoja: es el mismo dato (`otros`) rotulado distinto por cada DataWindow.
     *
     * Los cuatro desplegables van con los códigos de sus `values` (`Si	N/No	A/` y `Normal	N/Anormal	A/`).
     * `prostata` y `vejiga_urina` **no** están (van ocultas en ginecología) y `puro_texto` tampoco (se
     * muestra y no se edita).
     *
     * @return array<int,array{titulo:string, icono:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        return [
            ['titulo' => 'El encabezado', 'icono' => 'bi-calendar-heart', 'campos' => [
                ['columna' => 'fecha', 'etiqueta' => 'Fecha', 'tipo' => 'fecha',
                    'ayuda' => 'La fecha del estudio: abre con la de hoy y se puede cambiar.'],
                // ⚠️ `transductor` es el nombre real de la columna (a diferencia del `tranductor` con el typo
                // del eco mamario): no se renombra.
                ['columna' => 'transductor', 'etiqueta' => 'Transductor', 'tipo' => 'texto', 'tope' => 30,
                    'ayuda' => 'El transductor con el que se hizo el estudio (la columna es un texto corto de 30).'],
                // El desplegable de médicos: guarda la cédula y muestra el nombre (su `dddw` del escritorio,
                // `d_dwww_medicos`, tiene `cedula` como data column y `nombre` como display). Las opciones las
                // completa `EcoAbdominalVista` con la libreta del médico.
                ['columna' => self::COLUMNA_REFERIDO, 'etiqueta' => 'Referido por', 'tipo' => 'opciones',
                    'opciones' => [], 'ayuda' => 'El médico que refirió a la paciente, de tu catálogo de colegas.'],
            ]],

            ['titulo' => 'Los órganos', 'icono' => 'bi-clipboard2-pulse', 'campos' => [
                ['columna' => 'higado', 'etiqueta' => 'Hígado', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos del hígado.'],
                ['columna' => 'vesicula_bilial', 'etiqueta' => 'Vesícula biliar', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos de la vesícula biliar.'],
                ['columna' => 'porta_coledoco', 'etiqueta' => 'Porta y colédoco', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos de la porta y el colédoco.'],
                ['columna' => 'pancreas', 'etiqueta' => 'Páncreas', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos del páncreas.'],
                ['columna' => 'rinon_derecho', 'etiqueta' => 'Rinón derecho', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos del riñón derecho.'],
                ['columna' => 'rinon_izquierdo', 'etiqueta' => 'Rinón izquierdo', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos del riñón izquierdo.'],
            ]],

            // Los cuatro desplegables con códigos, en el orden del DataWindow. `vol_res_v_p_m` y `ascitis`
            // guardan Si→N y No→A (al revés de las casillas Sí/No de los obstétricos, que guardan S/N).
            // `prostata` y `vejiga_urina` no están: van ocultas en ginecología.
            ['titulo' => 'El resto del abdomen', 'icono' => 'bi-droplet', 'campos' => [
                ['columna' => 'vol_res_v_p_m', 'etiqueta' => 'Volumen residual vesical Post-Micción',
                    'tipo' => 'opciones', 'opciones' => self::siNoInvertido(),
                    'ayuda' => 'El volumen residual vesical post-micción: Sí o No.'],
                ['columna' => 'ascitis', 'etiqueta' => 'Ascitis', 'tipo' => 'opciones',
                    'opciones' => self::siNoInvertido(), 'ayuda' => 'Si hay ascitis: Sí o No.'],
                ['columna' => 'retroperitoneo', 'etiqueta' => 'Retroperitoneo', 'tipo' => 'opciones',
                    'opciones' => self::normalAnormal(), 'ayuda' => 'El retroperitoneo: Normal o Anormal.'],
                ['columna' => 'vasos_sanguineos', 'etiqueta' => 'Vasos sanguíneos', 'tipo' => 'opciones',
                    'opciones' => self::normalAnormal(), 'ayuda' => 'Los vasos sanguíneos: Normal o Anormal.'],
            ]],

            ['titulo' => 'Las observaciones y las conclusiones', 'icono' => 'bi-file-earmark-text', 'campos' => [
                // ⚠️ El área se llama «Otros» en el formulario y «Observaciones» en la hoja: es el mismo dato.
                ['columna' => 'otros', 'etiqueta' => 'Otros', 'tipo' => 'area',
                    'ayuda' => 'Lo que no entra en los bloques anteriores. La hoja lo imprime como «Observaciones».'],
                ['columna' => 'conclusiones', 'etiqueta' => 'Conclusiones', 'tipo' => 'area',
                    'ayuda' => 'Las conclusiones del estudio, en texto libre.'],
            ]],
        ];
    }

    /**
     * Las columnas que la pantalla edita, en el orden del mapa: es lo que la Action guarda.
     *
     * `prostata` y `vejiga_urina` **no** están (son de urología y van ocultas en ginecología) y `puro_texto`
     * tampoco (es el informe en texto libre del escritorio, que se muestra y no se edita).
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
     * Para `referido` devuelve `[]` a propósito: sus opciones son la libreta del médico y las arma el
     * ViewModel, no el mapa.
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
     * está en la lista (el referido, que guarda una cédula, se resuelve con `DoctoresDelMedico` donde se
     * imprime). Es la misma regla que usan los otros ecos.
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
     * eco abdominal (la pantalla lo muestra en blanco y la hoja no se ofrece).
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * La fila del eco abdominal de una consulta, o `null`.
     *
     * Se busca por la clave exacta del DataWindow —`reg_medico` (del contexto) + **`historia`** +
     * **`consulta`**—: una fila por consulta.
     */
    public function filaDe(Consulta $consulta): ?UltraAbdominalModelo
    {
        return UltraAbdominalModelo::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila del estudio de una consulta **para escribir**: la que ya existe o una nueva con su clave puesta
     * (`reg_medico`, `historia` y `consulta`) para que la Action sólo tenga que completar lo que el médico
     * cargó. La clave la arma el mapa del dato y no el formulario.
     */
    public function filaParaEscribir(Consulta $consulta): UltraAbdominalModelo
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new UltraAbdominalModelo([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * Las opciones de las dos casillas de Sí/No del abdominal, con **los códigos invertidos** respecto de las
     * de los obstétricos: el `values` del DataWindow es `Si	N/No	A/`, o sea **Sí → `N`** y **No → `A`**.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    private static function siNoInvertido(): array
    {
        return [
            ['valor' => 'N', 'texto' => 'Sí'],
            ['valor' => 'A', 'texto' => 'No'],
        ];
    }

    /**
     * Las opciones de los dos desplegables Normal/Anormal: el `values` del DataWindow es
     * `Normal	N/Anormal	A/`.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    private static function normalAnormal(): array
    {
        return [
            ['valor' => 'N', 'texto' => 'Normal'],
            ['valor' => 'A', 'texto' => 'Anormal'],
        ];
    }
}
