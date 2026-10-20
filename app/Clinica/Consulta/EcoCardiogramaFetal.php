<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EcoCardiogramaFetal as EcoCardiogramaFetalModelo;

/**
 * **El ecocardiograma fetal de una consulta** (WEB-6.3): su mapa del dato, su lectura y la precarga de la
 * ventana del escritorio.
 *
 * Es el quinto tipo de la sección de estudio (ítem 5 del panel del legado, `TiposDeEstudio::FETAL`) y el
 * hermano de `EcoDoppler` (WEB-6.3), `EcoObstetrico` (WEB-6.2) y `EcoGinecologico` (WEB-6.1): comparte con
 * ellos la forma —**una fila por consulta**, el mismo objeto de valores `EstudioEcografico` y la misma regla
 * de "esto es un documento o no" (`QueEsUnDocumento::ecografia`)— y se diferencia en tres cosas:
 *
 *  - **La tabla y su clave**: `ecocadiograma_fetal` (⚠️ **con el error de tipeo del legado**, sin la «r»: así
 *    está en el DataWindow, en el dump y en la migración, y no se renombra — regla 2 de `AGENTS.md`—),
 *    llaveada por **`historia` + `consulta`** (`consulta` es `NOT NULL`): la clave del DataWindow
 *    (`d_ecocardiograma_fetal.srd:6-7`, las dos `key=yes`). **No confundir con la ecocardiografía adulta**
 *    (`ecocardiografia`, `ecocardiograma_ete`, `ecocardiografico_in`), que es de cardiología: la fetal es la
 *    tabla que la especialidad declara **propia de ginecología** (`config/especialidades/ginecologia.php:57`,
 *    trampa 124).
 *  - **La ficha**: `fecha`, `fur`, `gesta`, `parto` («PARA:»), `aborto`, `cesarea` y `otros` son del
 *    encabezado del informe y **no cuentan** como hallazgo del estudio (`COLUMNAS_QUE_NO_CUENTAN`).
 *  - **La precarga**: al abrir una consulta **sin fila**, la ventana del escritorio precarga `texto_inicio` y
 *    `anatomi_datos` con dos textos fijos y la fecha de hoy (`w_cargar_visualizar_ecocardiograma_fetal.srw`
 *    `:174-182`). Ver `deParaLaPantalla()` y `precarga()`.
 *
 * ⚠️ **El DataWindow vivo no está donde parece** (trampas 102, 123 y 124): `d_ecocardiograma_fetal` **no**
 * está en `datawin_procesos/` sino en `fuentes/ventana_consulta/d_ecocardiograma_fetal.srd`, y es el que la
 * ventana usa de verdad (`w_cargar_visualizar_ecocardiograma_fetal.srw:537`, `retrieve` en `:174`).
 *
 * ⚠️ **`eg` y `referido`** existen en la tabla y en el DataWindow, pero van **ocultos** (`visible="0"`) y en
 * ginecología no se editan ni se imprimen: no entran en el mapa y la web **no los toca** al guardar.
 */
final class EcoCardiogramaFetal
{
    use MapaDelEcoObstetrico;

    /** La tabla del ecocardiograma fetal: **con el error de tipeo del legado**, tal cual. */
    public const TABLA = 'ecocadiograma_fetal';

    /** La columna de la historia, como en el DataWindow (`key=yes`): **es `historia`**, no `numhistoria`. */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /** El memo grande del estudio (el que la ventana del escritorio precarga). */
    public const COLUMNA_TEXTO_INICIO = 'texto_inicio';

    /** Los datos anatómicos (el otro memo que la ventana precarga). */
    public const COLUMNA_ANATOMIA = 'anatomi_datos';

    /**
     * Las columnas de la **ficha** que no cuentan para decidir si el estudio dice algo: son del encabezado del
     * informe (la fecha, la FUR, la gesta y la paridad), no hallazgos del estudio. Es la lista que comparten la
     * Action (`GuardarEcoCardiogramaFetal`), la pantalla (`EcoCardiogramaFetalVista`), la impresión y la
     * historia del paciente.
     *
     * Los dos memos (`texto_inicio` y `anatomi_datos`) **sí** cuentan: son el cuerpo del informe.
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_CUENTAN = ['fecha', 'fur', 'gesta', 'parto', 'aborto', 'cesarea', 'otros'];

    /**
     * El texto que la ventana del escritorio escribe en `texto_inicio` al abrir una consulta **sin fila**
     * (`w_cargar_visualizar_ecocardiograma_fetal.srw:180`), **copiado literalmente** (con sus dos espacios
     * dobles, que son los del fuente).
     */
    public const TEXTO_INICIAL = 'SE REALIZA EXPLORACIÓN CON TRANSDUCTOR DE 3,5 MHZ  EVIDENCIÁNDOSE: FETO ÚNICO EN SITUACIÓN LONGITUDINAL, PRESENTACIÓN CEFÁLICO. MOVIMIENTOS Y ACTIVIDAD CARDÍACA PRESENTES.';

    /**
     * El texto que la ventana escribe en `anatomi_datos` en la misma precarga
     * (`w_cargar_visualizar_ecocardiograma_fetal.srw:181`), copiado literalmente.
     */
    public const TEXTO_ANATOMICO = 'CORAZON EN SITUS SOLITUS, TETRACAMERAL. TABIQUES INTERVENTRICULAR E INTERAURICULAR ASPECTO NORMAL. FORAMEN OVAL PERMEABLE CON SHUNT DE DERECHA A IZQUIERDA. DOS VÁLVULAS AURICULOVENTRICULARES. SINCRONISMO CONTRÁCTIL. GRANDES VASOS CRUZADOS. EJE CARDIACO 45  A LA IZQUIERDA. RELACION CARDIOTORACICA 1/3.';

    /**
     * **Los dos textos de la precarga del escritorio**, por columna. Es lo que la pantalla muestra al abrir una
     * consulta que todavía no tiene fila (ver `deParaLaPantalla`).
     *
     * @return array<string,string>
     */
    public static function precarga(): array
    {
        return [
            self::COLUMNA_TEXTO_INICIO => self::TEXTO_INICIAL,
            self::COLUMNA_ANATOMIA => self::TEXTO_ANATOMICO,
        ];
    }

    /**
     * **El mapa del dato del ecocardiograma fetal**, en los seis bloques del formulario y en el orden del
     * DataWindow vivo.
     *
     * Los rótulos son los del escritorio y los `códigos` de cada lista, los de sus `values`. Tres avisos:
     *
     *  - **`biome_relacion` es la «MEDIDA:»** del bloque biométrico. En la base es un `INT` (y el escritorio la
     *    escribe como texto libre): acá se trata como **número**, que es lo que la columna es, y la hoja le
     *    agrega «(Percentil)» como su `compute` (`sufijo`).
     *  - **La placenta desempata `Previa` con `M`**: el `values` del DataWindow tiene el código `P`
     *    **duplicado** (Posterior y Previa). La web ya desempató Previa con `M` en el eco del 3.er trimestre
     *    (2026-10-13) y acá se sigue el mismo criterio: es una **desviación consciente** y está comentada.
     *  - **`Grado` guarda `O` para el grado 0** (la letra, no el cero), como el `values` del escritorio.
     *
     * @return array<int,array{titulo:string, icono:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        return [
            ['titulo' => 'Ficha', 'icono' => 'bi-calendar-heart', 'campos' => [
                self::fecha('fecha', 'Fecha',
                    'La fecha del estudio: abre con la de hoy y se puede cambiar.'),
                self::fechaTecleada('fur', 'FUR', 'dd/mm/aaaa'),
                ['columna' => 'gesta', 'etiqueta' => 'Gesta', 'tipo' => 'opciones',
                    'opciones' => self::romanos(), 'ayuda' => 'El número de la gesta que se está siguiendo.'],
                self::numero('parto', 'Para', ''),
                self::numero('aborto', 'Abortos', 'Los abortos.'),
                self::numero('cesarea', 'Cesáreas', 'Las cesáreas.'),
                ['columna' => 'otros', 'etiqueta' => 'Otros', 'tipo' => 'texto', 'tope' => 20,
                    'ayuda' => 'La obstetricia que no entra en las casillas anteriores.'],
            ]],

            ['titulo' => 'El estudio', 'icono' => 'bi-clipboard2-pulse', 'campos' => [
                // El memo grande del estudio: el DataWindow **no le pone rótulo** y su ventana lo precarga al
                // abrir una consulta sin fila.
                ['columna' => self::COLUMNA_TEXTO_INICIO, 'etiqueta' => '', 'tipo' => 'area',
                    'ayuda' => 'Informe del estudio'],
            ]],

            ['titulo' => 'Datos biométricos', 'icono' => 'bi-rulers', 'campos' => [
                self::medida('biome_dbp', 'DBP'),
                self::medida('biome_dof', 'DOF'),
                self::medida('biome_cc', 'CC'),
                self::medida('biome_humero', 'Húmero'),
                self::medida('biome_femur', 'Fémur'),
                self::medida('biome_saco', 'Sacro'),
                self::medida('biome_ca', 'CT'),
                self::medida('biome_cardiaca', 'Cardíaca'),
                self::medida('biome_toraxica', 'Torácica'),
                // La «MEDIDA:» del escritorio: la columna es un `INT`, así que va como número, y la hoja le
                // agrega el «(Percentil)» de su `compute`.
                self::medida('biome_relacion', 'Medida', null, '(Percentil)'),
            ]],

            ['titulo' => 'Datos anatómicos', 'icono' => 'bi-heart-pulse', 'campos' => [
                // El segundo memo: tampoco lleva rótulo, porque el bloque ya lo titula.
                ['columna' => self::COLUMNA_ANATOMIA, 'etiqueta' => '', 'tipo' => 'area',
                    'ayuda' => 'Hallazgos anatómicos del corazón fetal'],
            ]],

            ['titulo' => 'Datos placento - funiculares', 'icono' => 'bi-diagram-3', 'campos' => [
                self::lista('pla_funi_placenta', 'Placenta', [
                    'Anterior' => 'A',
                    'Posterior' => 'P',
                    'Fúndica' => 'F',
                    'Lateral Derecho' => 'D',
                    'Lateral Izquierdo' => 'I',
                    // ⚠️ El `values` del DataWindow tiene el código `P` duplicado: «Posterior P/…/Previa P/».
                    // La web desempata Previa con `M`, la misma desviación consciente del eco del 3.er
                    // trimestre (2026-10-13): un código repetido no puede leerse de vuelta.
                    'Previa' => 'M',
                ], ''),
                self::lista('pla_funi_grado', 'Grado',
                    ['O' => '0', 'I' => 'I', 'II' => 'II', 'III' => 'III'],
                    '') + ['sufijo' => '/ III'],
                self::medida('pla_funi_grosor', 'Groso'),
                self::lista('pla_funi_cordon_umb', 'Cordón umbilical',
                    ['3 ELEMENTOS VASCULARES' => 'N', 'ANORMAL' => 'A']),
                self::lista('pla_funi_liquido_anm', 'Líquido amniótico',
                    ['Normal' => 'N', 'Oligohidramnios' => 'O', 'Polihidramnios' => 'P']),
                self::medida('pla_funi_pef', 'Peso fetal', null, 'grs. (Percentil)'),
                self::lista('pla_funi_sexo', 'Sexo', ['Masculino' => 'M', 'Femenino' => 'F']),
            ]],

            ['titulo' => 'Conclusión', 'icono' => 'bi-file-earmark-text', 'campos' => [
                ['columna' => 'conclusion', 'etiqueta' => 'Signos ecográficos sugestivos de', 'tipo' => 'area',
                    'ayuda' => ''],
                ['columna' => 'sugerencia', 'etiqueta' => 'Sugerencias', 'tipo' => 'area',
                    'ayuda' => ''],
            ]],
        ];
    }

    /**
     * Las columnas que la pantalla edita, en el orden del mapa: es lo que la Action guarda.
     *
     * `eg` y `referido` **no** están: existen en la tabla, van ocultos en el DataWindow y en ginecología no se
     * editan ni se imprimen, así que la web no los toca.
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

    /**
     * Los campos de un bloque del mapa, por su título (`'Datos biométricos'`). Es lo que usa la hoja para armar
     * un bloque sin copiar su lista de columnas: un campo nuevo del mapa sale impreso sin tocar la impresión.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function camposDelBloque(string $titulo): array
    {
        foreach (self::grupos() as $grupo) {
            if ($grupo['titulo'] === $titulo) {
                return $grupo['campos'];
            }
        }

        return [];
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
     * regla que usa el eco Doppler (`EcoDoppler::textoDe`).
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
     * El estudio de una consulta **tal como quedó guardado**: sus valores normalizados, o el objeto **vacío**
     * si esa consulta no tiene ecocardiograma fetal (la hoja no se ofrece).
     *
     * Es la lectura que usan la impresión y la historia del paciente: **no** aplica la precarga del escritorio,
     * porque una consulta sin fila no tiene un informe que imprimir.
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * El estudio de una consulta **como lo abre la pantalla**: si la consulta todavía no tiene fila, con los
     * dos textos que la ventana del escritorio precarga en su `open`
     * (`w_cargar_visualizar_ecocardiograma_fetal.srw:174-182`).
     *
     * La precarga es **sólo de la pantalla** y no se guarda sola: la fila se crea recién cuando el médico
     * aprieta Guardar, con lo que el formulario mande (que, recién abierto, son estos textos).
     */
    public function deParaLaPantalla(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return $fila === null
            ? new EstudioEcografico(self::precarga())
            : new EstudioEcografico($fila->getAttributes());
    }

    /**
     * La fila del ecocardiograma fetal de una consulta, o `null`.
     *
     * Se busca por la clave exacta del DataWindow —`reg_medico` (del contexto) + **`historia`** +
     * **`consulta`**—: una fila por consulta.
     */
    public function filaDe(Consulta $consulta): ?EcoCardiogramaFetalModelo
    {
        return EcoCardiogramaFetalModelo::query()
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
    public function filaParaEscribir(Consulta $consulta): EcoCardiogramaFetalModelo
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoCardiogramaFetalModelo([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }

    /**
     * Una **medida** del estudio: su columna, su rótulo y —lo que la distingue de un texto— su unidad y, si
     * la hoja le agrega algo más que la unidad, su `sufijo` (el «(Percentil)» del `compute` del escritorio).
     *
     * La `unidad` la muestra la pantalla al lado del campo y la agrega la hoja después del número; el `sufijo`
     * **sólo** lo agrega la hoja.
     */
    private static function medida(string $columna, string $etiqueta, ?string $unidad = 'mm', ?string $sufijo = null): array
    {
        return [
            'columna' => $columna,
            'etiqueta' => $etiqueta,
            'tipo' => 'numero',
            'unidad' => $unidad,
            'sufijo' => $sufijo,
            'ayuda' => $unidad === null
                ? ''
                : 'La medida en ' . $unidad . ' (el informe la imprime así).',
        ];
    }

    /**
     * Las opciones del desplegable de la gesta: los números romanos **I a XII** con el código `1..12` que
     * guarda el escritorio (su `values` llega hasta `XII 12/`).
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
