<?php

namespace App\Clinica\Consulta;

use App\Clinica\FechaDelConsultorio;
use App\Models\Consulta;
use App\Models\EcoPelvico;

/**
 * **El eco pélvico (ginecológico) de una consulta** (WEB-6.1, ítem 5 del panel del legado): lo lee de
 * `eco_pelvico` y arma el **mapa de campos** que pintan la pantalla y el informe.
 *
 * Es el estudio que fija el patrón de los demás de F6, así que lo que se decide acá se repite en el eco
 * obstétrico, el doppler y los ultrasonidos.
 *
 * ## La fuente
 *
 * | Qué | Dónde |
 * |---|---|
 * | La ventana | `w_cargar_visualizar_ecografia` (`ventana_consulta/w_cargar_visualizar_ecografia.srw`) |
 * | El DataWindow del formulario | `ventana_consulta/d_eco_pelvico.srd` — columnas y claves en `:6-47`, rótulos y controles en `:49-118` |
 * | El informe | `datawin_reportes/d_eco_pelvico_print.srd` — «INFORME ECOSONOGRAFICO GINECOLÓGICO» (`:54`) |
 * | El informe del modo texto libre | `datawin_reportes/d_eco_pelvico_print_puro_t.srd` |
 *
 * ## Lo que la fuente dice y no es obvio
 *
 *  1. **Una fila por consulta, con clave `(numhistoria, nroconsulta)`.** Las dos columnas llevan
 *     `key=yes` (`d_eco_pelvico.srd:6` y `:19`), el `retrieve` cruza por las dos (`:48`) y el escritorio
 *     la crea con `InsertRow` + `numhistoria` + `fecha = today()` + `nroconsulta` cuando el `retrieve`
 *     vuelve vacío (`w_cargar_visualizar_ecografia.srw:216-224`). Guarda con `dw_1.Update()`
 *     (`:539`, `:562`, `:815`, `:832`). **La fecha del estudio la propone hoy.**
 *  2. **Las columnas se llaman `numhistoria`/`nroconsulta`**, no `historia`/`consulta` como en
 *     `examen_fisico_nuevo`: la clave se busca por esos nombres (el error que se midió en WEB-4.3 fue
 *     buscar por el id de la consulta y dejar la fila huérfana).
 *  3. **Los desplegables tienen códigos del legado.** El DataWindow declara los `values` de cada columna
 *     (`:8-46`) con la forma `rótulo<TAB>código/…`: el código guardado es un `1`, un `H` o un `AVF`
 *     según la columna, y el rótulo que ve el médico es el texto. El mapa los conserva **tal cual**, y un
 *     código que no esté en la lista se muestra como está (igual que el `codeparentesco` de WEB-3.4:
 *     los datos del escritorio pueden traer cualquier cosa y no se inventa una traducción).
 *  4. **`fondosacolibre` no es el fondo de saco: es la forma del útero.** Está rotulada *Forma:* en el
 *     bloque **ÚTERO** (`:62`) y sus valores son `Globosa`/`Piriforme` (`:26`). El fondo de saco de
 *     Douglas es la **otra** columna, `fondosacootro` (el texto libre del rótulo *Fondo de Saco Douglas:*
 *     de `:76`). El nombre de la columna es el del esquema del legado y no se renombra.
 *  5. **`observacion` es *Anexos*** (`:78`), no una observación general: las otras dos observaciones del
 *     informe son `observacionovario` (*Otros Hallazgos*, `:113`) e `idxecografico` (*Observaciones*,
 *     `:115`), y las conclusiones van en `sugerencias` (`:116`).
 *  6. **El modo «puro texto» del escritorio** (un `mle_1` con el informe entero, `:268-277`, que se
 *     imprime con `d_eco_pelvico_print_puro_t`) guarda en **`puro_texto`** y la web **todavía no lo
 *     edita**: se muestra si el escritorio lo dejó escrito (ver `columnasQueNoSeTocan()`).
 *
 * El mapa vive acá y no en el ViewModel porque es **el mapa del dato** —qué columna, con qué rótulo, con
 * qué códigos—, y las tres capas lo comparten: el formulario, la Action que escribe y el informe.
 */
final class EcoGinecologico
{
    /** La tabla del estudio. */
    public const TABLA = 'eco_pelvico';

    /** Las dos columnas de la clave del legado, en el orden en que las busca el DataWindow. */
    public const COLUMNA_HISTORIA = 'numhistoria';
    public const COLUMNA_CONSULTA = 'nroconsulta';

    /** La fecha del estudio: la propone hoy el escritorio y la imprime la cabecera del informe. */
    public const COLUMNA_FECHA = 'fecha';

    /** La fecha de la última regla. */
    public const COLUMNA_FUR = 'fur';

    /** El informe en texto libre del escritorio: se muestra y no se edita (fase siguiente). */
    public const COLUMNA_PURO_TEXTO = 'puro_texto';

    /** El nombre del estudio, para la pantalla y para el título del informe. */
    public const TITULO = 'Eco pélvico';

    /** El título del informe, el del DataWindow de impresión (`d_eco_pelvico_print.srd:54`). */
    public const TITULO_DEL_INFORME = 'Informe ecosonográfico ginecológico';

    /**
     * Los grupos del formulario, en el orden de la pantalla: cada grupo es un bloque con su título y sus
     * campos. El orden sale de la posición vertical de los controles del DataWindow (la cabecera arriba y
     * después ÚTERO, OVARIOS y las observaciones).
     *
     * `tipo` dice con qué control se pinta y lo obedece la vista:
     *
     *  - `opciones` — un `<select>` con los códigos del legado (lleva `opciones`);
     *  - `numero` — un `<input type="number">` (las `decimal(5,2)` y los `double` de volúmenes);
     *  - `texto` — un `<input type="text">` (los `char` cortos, como `PARA` y `fondosacootro`);
     *  - `area` — un `<textarea>` (los `text` largos);
     *  - `fecha` — un `<input type="date">`.
     *
     * Las **unidades no están en la fuente**: el escritorio usa máscaras (`#####` para las medidas y
     * `###,###.00` para los volúmenes) y no escribe ninguna unidad en la pantalla ni en el informe, así
     * que la web muestra el número como viene y **no** inventa un `mm`.
     *
     * @return array<int,array{titulo:string,icono:string,ayuda:string,campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        return [
            [
                'titulo' => 'Datos del estudio',
                'icono' => 'bi-calendar2-check',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'gesta', 'etiqueta' => 'Gesta', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('I	1/II	2/III	3/IV	4/V	5/VI	6/VII	7/VIII	8/IX	9/X	10/XI	11/0	0'),
                        'ayuda' => ''],
                    ['columna' => self::COLUMNA_FUR, 'etiqueta' => 'FUR', 'tipo' => 'fecha', 'unidad' => null,
                        'ayuda' => ''],
                    ['columna' => 'PARA', 'etiqueta' => 'PARA', 'tipo' => 'texto', 'unidad' => null,
                        'ayuda' => ''],
                    ['columna' => self::COLUMNA_FECHA, 'etiqueta' => 'Fecha del estudio', 'tipo' => 'fecha', 'unidad' => null,
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Transductor',
                'icono' => 'bi-broadcast',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'trans_convex', 'etiqueta' => 'Convex', 'tipo' => 'opciones', 'unidad' => 'MHz',
                        'opciones' => self::deLaLista('2.7	2.7/3.5	3.5/5	5'),
                        'ayuda' => ''],
                    ['columna' => 'trans_transvaginal', 'etiqueta' => 'Transvaginal', 'tipo' => 'opciones', 'unidad' => 'MHz',
                        'opciones' => self::deLaLista('5	5/6.3	6.3/7.5	7.5'),
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Vejiga',
                'icono' => 'bi-droplet',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'vagina', 'etiqueta' => 'Vejiga', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Llena	H/Vacia	V'),
                        'ayuda' => ''],
                    ['columna' => 'vagina_llena', 'etiqueta' => 'Vejiga llena', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Normal	L/Anormal	S'),
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Útero',
                'icono' => 'bi-circle',
                'ayuda' => 'Posición, forma, borde, patrón miometrial, medidas y endometrio.',
                'campos' => [
                    ['columna' => 'posicionavf', 'etiqueta' => 'Posición', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('AVF	1/RVF	2/Indiferente	3'),
                        'ayuda' => 'Anteversoflexión, retroversoflexión o indiferente.'],
                    ['columna' => 'posicionavf_d', 'etiqueta' => 'Posición AVF', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Derecho	D/Izquierdo	I/Línea media	L'),
                        'ayuda' => 'Hacia dónde: el DataWindow declara los tres valores pero no le pone rótulo.'],
                    ['columna' => 'posicionrvf_d', 'etiqueta' => 'Posición RVF', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Derecha	D/Izquierda	I'),
                        'ayuda' => 'Hacia dónde: el DataWindow declara los dos valores pero no le pone rótulo.'],
                    ['columna' => 'fondosacolibre', 'etiqueta' => 'Forma', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Globosa	1/Piriforme	2'),
                        'ayuda' => ''],
                    ['columna' => 'bordeutero', 'etiqueta' => 'Borde', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Regular	1/Irregular	2'),
                        'ayuda' => 'Regular o irregular.'],
                    ['columna' => 'patron', 'etiqueta' => 'Patrón miometrial', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Homogéneo	1/Heterogéneo	2'),
                        'ayuda' => 'Homogéneo o heterogéneo.'],
                    ['columna' => 'cuerpo', 'etiqueta' => 'Cuerpo (longitud)', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'transverso', 'etiqueta' => 'Transverso', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'antero', 'etiqueta' => 'Antero posterior', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'linea', 'etiqueta' => 'Endometrio', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Visible	1/No visible	2'),
                        'ayuda' => 'Si la línea endometrial se ve.'],
                    ['columna' => 'linea_continuo', 'etiqueta' => 'Línea endometrial', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Continuo	C/Discontinuo	D'),
                        'ayuda' => 'Continua o discontinua.'],
                    ['columna' => 'linea_homo', 'etiqueta' => 'Línea endometrial (ecogenicidad)', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Homogénea	1/Heterogénea	2'),
                        'ayuda' => 'Homogénea o heterogénea.'],
                    ['columna' => 'linea_cavidad_u', 'etiqueta' => 'Cavidad uterina', 'tipo' => 'opciones', 'unidad' => null,
                        'opciones' => self::deLaLista('Llena	L/Vacía	V'),
                        'ayuda' => 'Llena o vacía.'],
                    ['columna' => 'espesor', 'etiqueta' => 'Espesor', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Fondo de saco de Douglas',
                'icono' => 'bi-arrow-down-circle',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'fondosacootro', 'etiqueta' => 'Fondo de saco de Douglas', 'tipo' => 'texto', 'unidad' => null,
                        'ayuda' => 'Hasta 80 caracteres, como la columna del legado.'],
                ],
            ],
            [
                'titulo' => 'Anexos',
                'icono' => 'bi-clipboard2-pulse',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'observacion', 'etiqueta' => 'Anexos', 'tipo' => 'area', 'unidad' => null,
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Ovario derecho',
                'icono' => 'bi-circle-half',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'ovarioderlong', 'etiqueta' => 'Longitud', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'ovarioderap', 'etiqueta' => 'Transverso', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'ovariodertras', 'etiqueta' => 'Antero posterior', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'volumen_der', 'etiqueta' => 'Volumen', 'tipo' => 'numero', 'unidad' => 'cc',
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Ovario izquierdo',
                'icono' => 'bi-circle-half',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'ovarioizqlong', 'etiqueta' => 'Longitud', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'ovarioizqap', 'etiqueta' => 'Transverso', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'ovarioizqtras', 'etiqueta' => 'Antero posterior', 'tipo' => 'numero', 'unidad' => 'mm',
                        'ayuda' => ''],
                    ['columna' => 'volumen_izq', 'etiqueta' => 'Volumen', 'tipo' => 'numero', 'unidad' => 'cc',
                        'ayuda' => ''],
                ],
            ],
            [
                'titulo' => 'Hallazgos, observaciones y conclusiones',
                'icono' => 'bi-chat-square-text',
                'ayuda' => '',
                'campos' => [
                    ['columna' => 'observacionovario', 'etiqueta' => 'Otros hallazgos', 'tipo' => 'area', 'unidad' => null,
                        'ayuda' => ''],
                    ['columna' => 'idxecografico', 'etiqueta' => 'Observaciones', 'tipo' => 'area', 'unidad' => null,
                        'ayuda' => ''],
                    ['columna' => 'sugerencias', 'etiqueta' => 'Conclusiones', 'tipo' => 'area', 'unidad' => null,
                        'ayuda' => ''],
                ],
            ],
        ];
    }

    /**
     * Todos los campos, aplanados y en el orden del formulario.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campos(): array
    {
        $campos = [];
        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $campos[] = $campo;
            }
        }

        return $campos;
    }

    /**
     * Las columnas que la web **edita**, con su tabla: es lo que comparten la validación del controlador,
     * el formulario y la Action que escribe (igual que `EvaluacionFisica::columnas()`).
     *
     * @return array<string,string>
     */
    public static function columnas(): array
    {
        $mapa = [];
        foreach (self::campos() as $campo) {
            $mapa[$campo['columna']] = self::TABLA;
        }

        return $mapa;
    }

    /**
     * Las columnas de `eco_pelvico` que la web **no** toca, con el motivo. Ninguna se borra ni se pisa:
     * si el escritorio las escribió, se quedan como están.
     *
     * @return array<string,string>
     */
    public static function columnasQueNoSeTocan(): array
    {
        return [
            'puro_texto' => 'El informe en texto libre del escritorio: la ventana lo edita en el modo "puro '
                . 'texto" (`mle_1`, `w_cargar_visualizar_ecografia.srw:268-277` y `:830`) y lo imprime con '
                . '`d_eco_pelvico_print_puro_t`. La web todavía no lo edita: lo muestra si está escrito.',
            'centrar' => 'La pantalla del eco pélvico no la muestra (no tiene control en `d_eco_pelvico.srd`); '
                . 'sí la lista el DataWindow de impresión, con los valores Derecho/Izquierdo (`d_eco_pelvico_print.srd:10`).',
            'cuello' => 'Medida del cuello uterino: está en la tabla y en el DataWindow del formulario, pero '
                . 'sin control en la pantalla (`d_eco_pelvico.srd:14`).',
            'anterocuello' => 'Medida del cuello uterino: igual que `cuello` (`d_eco_pelvico.srd:15`).',
            'formautero' => 'La forma del útero escrita a mano (`char(30)`): la columna existe y el '
                . 'DataWindow la declara (`d_eco_pelvico.srd:30`), pero esta pantalla no la edita.',
        ];
    }

    /** El rótulo de pantalla de una columna, o la columna si no está en el mapa. */
    public static function etiquetaDe(string $columna): string
    {
        foreach (self::campos() as $campo) {
            if ($campo['columna'] === $columna) {
                return $campo['etiqueta'];
            }
        }

        return $columna;
    }

    /**
     * Las opciones de una columna, con el **código** que se guarda y el **texto** que se muestra.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    public static function opcionesDe(string $columna): array
    {
        foreach (self::campos() as $campo) {
            if ($campo['columna'] === $columna && ($campo['tipo'] ?? null) === 'opciones') {
                return $campo['opciones'];
            }
        }

        return [];
    }

    /**
     * El texto que se muestra para un valor guardado: el rótulo de la opción, o **el valor tal cual** si
     * no está en la lista del DataWindow (los datos del escritorio pueden traer cualquier código).
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
     * Lee el eco pélvico de una consulta.
     *
     * La fila sale de `filaDe()`, que es la misma clave que usa la Action para escribir: la pantalla no
     * puede estar mostrando una fila y guardando en otra.
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * La fila de `eco_pelvico` de una consulta, o `null`.
     *
     * ⚠️ Se busca por **`numhistoria` + `nroconsulta`** —los números de la historia y de la consulta, que
     * es la clave del DataWindow (`d_eco_pelvico.srd:6`, `:19` y el `retrieve` de `:48`)— y acotada al
     * `reg_medico` de la consulta. Buscar por el id de la consulta no encuentra la fila del escritorio y
     * crea una nueva al lado (el error que se midió al verificar WEB-4.3).
     */
    public function filaDe(Consulta $consulta): ?EcoPelvico
    {
        return EcoPelvico::where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila para escribir sobre ella: la que ya existe o una nueva **sin guardar**, con la clave del
     * DataWindow y la fecha del estudio puesta en hoy (es lo que hace el escritorio al crear la fila,
     * `w_cargar_visualizar_ecografia.srw:221-224`).
     *
     * `numhistoria`, `nroconsulta` y `fecha` son `NOT NULL` en la tabla: una fila nueva sin ellas no se
     * puede guardar.
     */
    public function filaParaEscribir(Consulta $consulta): EcoPelvico
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoPelvico([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
            self::COLUMNA_FECHA => FechaDelConsultorio::hoyComoFecha()->toDateString(),
        ]);
    }

    /**
     * Traduce la lista `rótulo<TAB>código/…` de un `values` del DataWindow a las opciones del formulario.
     *
     * Es la forma literal en que PowerBuilder guarda los códigos (`values="AVF	1/RVF	2/…"`), así que la
     * lista se escribe igual que en la fuente y se traduce acá: el `/` separa opciones y el tab el rótulo
     * del código.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    private static function deLaLista(string $valores): array
    {
        $opciones = [];

        foreach (explode('/', $valores) as $par) {
            $partes = explode("\t", $par, 2);

            if (count($partes) === 2) {
                $opciones[] = ['texto' => trim($partes[0]), 'valor' => trim($partes[1])];
            }
        }

        return $opciones;
    }
}
