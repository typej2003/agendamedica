<?php

namespace App\Clinica\Impresion;

/**
 * **Un documento de la consulta listo para imprimir en media hoja** (WEB-5.3a, WEB-5.3b, WEB-5.4): constancia, reposo, referencia,
 * informe, orden de hospitalización, dieta / recomendaciones, orden de laboratorio o solicitud de estudios. Lo arma `SolicitudesParaImprimir` y lo reparte en mitades `DistribuidorDeDocumento`.
 *
 * Es un objeto de valores ya resuelto, sin base de datos: de quién es (`persona`, la cabecera de
 * `PacienteImpreso`), de qué fecha y qué lleva el cuerpo:
 *
 *  - `fijos`: los datos cortos de arriba, uno por línea, con su rótulo en negrita (`REPOSO DURANTE: 8 día(s)`). **No se
 *    parten** entre mitades: van siempre en la primera.
 *  - `parrafos`: el texto, que **sí se parte por palabra** si no entra. Cada uno puede llevar un rótulo en negrita
 *    (`IDX:`, inline, o `MOTIVO DE LA REFERENCIA:`, en su propia línea). Un párrafo **de lista** (WEB-5.4, los exámenes de un tipo) lleva
 *    además `items`: el texto es esos elementos unidos por ` · ` y, si no entra, se parte **entre elementos** y nunca por palabra (un
 *    examen no queda cortado en dos mitades); la parte que sigue repite el rótulo con «(cont.)».
 *
 * `clave` es con lo que se pide el documento (`constancia`, `reposo`, `referencia-3`, `informe`, `hospitalizacion`, `dieta`, `laboratorio`, `estudios`): es la misma que usan la
 * historia del paciente, la lista de la cita de la agenda y las rutas de impresión.
 */
final class DocumentoImpreso
{
    public const CONSTANCIA = 'constancia';

    public const REPOSO = 'reposo';

    public const REFERENCIA = 'referencia';

    public const INFORME = 'informe';

    public const HOSPITALIZACION = 'hospitalizacion';

    public const DIETA = 'dieta';

    public const LABORATORIO = 'laboratorio';

    public const ESTUDIOS = 'estudios';

    /** La **orden de laboratorio de la pareja** (WEB-11.21): la hoja de laboratorio a nombre de la pareja. */
    public const LABORATORIO_PAREJA = 'laboratorio-pareja';

    /**
     * El **informe del estudio ecográfico** (WEB-6.1). Es el informe de un estudio, no una solicitud: la hoja lleva los
     * datos del estudio (medidas, hallazgos y conclusiones), no una orden.
     *
     * El título sale del DataWindow de impresión del escritorio (`d_eco_pelvico_print.srd:54`), con la tilde que a la
     * fuente le falta («ECOSONOGRAFICO»): la hoja es de la web y va con la ortografía correcta.
     */
    public const ECO_PELVICO = 'eco-pelvico';

    /**
     * El **informe del control de embarazo** (WEB-6.6, la pestaña *Reporte*): la hoja que el escritorio
     * imprime con `d_informe_pre_nata_peque.srd`, cuyo título es «CONTROL DE EMBARAZO» (`:19`).
     */
    public const CONTROL_DE_EMBARAZO = 'control-de-embarazo';

    /**
     * El **informe del eco obstétrico del primer trimestre** (WEB-6.2): la hoja que el escritorio imprime con
     * `d_eco_obstetrico_print_nuevo_grande`, cuyo título es «ECOSONOGRAFÍA OBSTÉTRICA» + «PRIMER TRIMESTRE».
     */
    public const ECO_OBSTETRICO = 'eco-obstetrico';

    /** El **informe del eco obstétrico del 3.er trimestre** (WEB-6.2): «… TERCER TRIMESTRE». */
    public const ECO_OBSTETRICO_TERCERO = 'eco-obstetrico-tercero';

    /** El **informe del eco obstétrico del 2.º trimestre** (WEB-11.14): «… SEGUNDO TRIMESTRE» (tabla `eco_obstetrico_tercer_2`). */
    public const ECO_OBSTETRICO_SEGUNDO = 'eco-obstetrico-segundo';

    /**
     * El **informe del eco Doppler perinatal** (WEB-6.3): la hoja que el escritorio imprime con
     * `d_eco_doppler_print`, cuyo título es «ECO DOPPLER PERINATAL» (`:55`) y cuyo bloque de la grilla de
     * cuatro vasos se titula «Eco Doppler Obstétrico en 4 Vasos» (`:63`).
     *
     * Su formato en blanco del escritorio (`d_eco_doppler_print_vacio`) es **otro diseño** (encabezado
     * condensado, grilla de 8 columnas en 2 y «Vena Umbilical:»); ver `FormatosEnBlanco`.
     */
    public const ECO_DOPPLER = 'eco-doppler';

    /**
     * El **informe del ecocardiograma fetal** (WEB-6.3): la hoja que el escritorio imprime con
     * `d_eco_ecocardiografia_fetal_print`, cuyo título es «ECOCARDIOGRAMA FETAL» (`:43`).
     *
     * ⚠️ **El escritorio NO tiene un formato en blanco para este estudio**: el panel
     * `miconsultat/w_panel_impresion_formatos.srw` lista seis formatos y el fetal no está entre ellos. Por eso
     * `FormatosEnBlanco` **no** lo ofrece (no es un olvido: es lo que la fuente tiene).
     */
    public const ECO_CARDIOGRAMA_FETAL = 'eco-cardiograma-fetal';

    /**
     * El **informe del eco mamario** (WEB-6.4): la hoja que el escritorio imprime con
     * `d_ultasonido_mamas_print`, cuyo título es «ULTRASONIDO DE MAMAS» (`:26`). Su tabla (`ultra_mama`) no se
     * llama como su DataWindow (el vivo es `d_ultasonido_mamas`, en `fuentes/ventana_consulta/`: trampas 102,
     * 123 y 124) y su clave es `historia` + `consulta`.
     *
     * ⚠️ **El escritorio NO tiene un formato en blanco para este estudio**: el panel
     * `miconsultat/w_panel_impresion_formatos.srw` lista seis formatos y el mamario no está entre ellos. Por eso
     * `FormatosEnBlanco` **no** lo ofrece (no es un olvido: es lo que la fuente tiene).
     */
    public const ECO_MAMARIO = 'eco-mamario';

    /**
     * El **informe del eco abdominal** (WEB-6.4): la hoja que el escritorio imprime con
     * `d_ultasonido_abdominal_print`, cuyo título es «ULTRASONIDO ABDOMINAL» (`:37`). Es una ecografía
     * abdominal general: la misma tabla (`ultra_abdominal`) y el mismo DataWindow los usan cirugía y
     * pediatría, y su clave es `historia` + `consulta`.
     *
     * ⚠️ **El escritorio NO tiene un formato en blanco para este estudio**: el panel
     * `miconsultat/w_panel_impresion_formatos.srw` lista seis formatos y el abdominal no está entre ellos. Por
     * eso `FormatosEnBlanco` **no** lo ofrece (no es un olvido: es lo que la fuente tiene).
     */
    public const ECO_ABDOMINAL = 'eco-abdominal';

    /**
     * **El informe de la colposcopía** (WEB-6.5): la hoja del examen colposcópico del escritorio
     * (`d_colposcopia_1` / `d_colposcopia_2`, cuyo título es «INFORME DE COLPOSCOPÍA», `:25` y `:26`). Su
     * cuerpo es **el texto de `consultas.observaciones`** y el escritorio le anexa las fotos del examen
     * (`d_imagenes_consul_pac_colposco` en el `_1`, `d_imagen_colpo_fija_2` en el `_2`: los dos informes son
     * el mismo con otra forma de anexarlas).
     */
    public const COLPOSCOPIA = 'colposcopia';

    /**
     * El **informe de histeroscopía** (WEB-6.5): la hoja del escritorio es `d_histeroscopia`, cuyo título es
     * «INFORME DE HISTEROSCOPÍA» (`:27`) y cuyo cuerpo es el texto de `consultas.cuello` (`:29`, con las fotos
     * de su subreporte `d_imagenes_consul_pac_histero`).
     */
    public const HISTEROSCOPIA = 'histeroscopia';

    /**
     * El **resumen médico** (WEB-5.3c): la hoja que el escritorio arma sola con los datos de **toda** la
     * consulta (`d_resumen_medico_gra`, el radio «Resumen médico» de la pestaña *Informe médico*,
     * `w_consulta_principal.srw:18653`). No es una fila de nadie: no se guarda ni se edita, y sale —o no—
     * según lo que la consulta tenga cargado.
     *
     * Es **otro** resumen que el de la historia clínica (`ResumenDeHistoriaVista`, que recorre todas las
     * consultas): éste es de **una** consulta y lleva los rótulos de su DataWindow, que no son los de la
     * historia (`consultas.oido` es «RESULTADO DE EXÁMENES» acá y «Oído» allá; `consultas.laringe`,
     * «PLAN DE TRATAMIENTO» y «Laringe»).
     */
    public const RESUMEN_MEDICO = 'resumen-medico';

    /**
     * El título que se imprime arriba del cuerpo y el nombre con que aparece en las listas, de cada documento. Es **la** fuente de
     * esos textos: la hoja real (`SolicitudesParaImprimir`) y la hoja en blanco (`FormatosEnBlanco`, WEB-5.5b) leen de acá.
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const TITULOS = [
        self::CONSTANCIA => ['CONSTANCIA', 'Constancia'],
        self::REPOSO => ['REPOSO MÉDICO', 'Reposo médico'],
        self::REFERENCIA => ['REFERENCIA', 'Referencia'],
        self::INFORME => ['INFORME MÉDICO', 'Informe médico'],
        self::HOSPITALIZACION => ['ORDEN DE HOSPITALIZACIÓN', 'Orden de hospitalización'],
        self::DIETA => ['DIETA / RECOMENDACIONES', 'Dieta / Recomendaciones'],
        self::LABORATORIO => ['SOLICITUD DE EXÁMENES DE LABORATORIO', 'Orden de laboratorio'],
        self::LABORATORIO_PAREJA => ['SOLICITUD DE EXÁMENES DE LABORATORIO', 'Orden de laboratorio de la pareja'],
        self::ESTUDIOS => ['SOLICITUD DE ESTUDIOS', 'Solicitud de estudios'],
        self::ECO_PELVICO => ['INFORME ECOSONOGRÁFICO GINECOLÓGICO', 'Eco pélvico'],
        // El título del DataWindow del informe prenatal, tal cual (`d_informe_pre_nata_peque.srd:19`).
        self::CONTROL_DE_EMBARAZO => ['CONTROL DE EMBARAZO', 'Control de embarazo'],
        // El del eco obstétrico del primer trimestre (`d_eco_obstetrico_print_nuevo_grande`).
        self::ECO_OBSTETRICO => ['ECOSONOGRAFÍA OBSTÉTRICA', 'Eco obstétrico (1.er trimestre)'],
        self::ECO_OBSTETRICO_SEGUNDO => ['ECOSONOGRAFÍA OBSTÉTRICA', 'Eco obstétrico (2.º trimestre)'],
        self::ECO_OBSTETRICO_TERCERO => ['ECOSONOGRAFÍA OBSTÉTRICA', 'Eco obstétrico (3.er trimestre)'],
        // El del eco Doppler perinatal, tal cual el DataWindow de impresión del escritorio
        // (`d_eco_doppler_print.srd:55`).
        self::ECO_DOPPLER => ['ECO DOPPLER PERINATAL', 'Eco Doppler perinatal'],
        // El del ecocardiograma fetal, tal cual el DataWindow de impresión del escritorio
        // (`d_eco_ecocardiografia_fetal_print.srd:43`).
        self::ECO_CARDIOGRAMA_FETAL => ['ECOCARDIOGRAMA FETAL', 'Ecocardiograma fetal'],
        // El del eco mamario, tal cual el DataWindow de impresión del escritorio
        // (`d_ultasonido_mamas_print.srd:26`).
        self::ECO_MAMARIO => ['ULTRASONIDO DE MAMAS', 'Eco mamario'],
        // El del eco abdominal, tal cual el DataWindow de impresión del escritorio
        // (`d_ultasonido_abdominal_print.srd:37`).
        self::ECO_ABDOMINAL => ['ULTRASONIDO ABDOMINAL', 'Eco abdominal'],
        // El del informe de colposcopía, tal cual el DataWindow del escritorio (`d_colposcopia_1.srd:25`).
        self::COLPOSCOPIA => ['INFORME DE COLPOSCOPÍA', 'Colposcopía'],
        // El de histeroscopía, tal cual el DataWindow del escritorio (`d_histeroscopia.srd:27`).
        self::HISTEROSCOPIA => ['INFORME DE HISTEROSCOPÍA', 'Histeroscopía'],
        // El del resumen médico: el título del radio del escritorio; el DataWindow no imprime un título
        // propio (su encabezado es el membrete), así que la hoja de la web lleva el nombre del documento.
        self::RESUMEN_MEDICO => ['RESUMEN MÉDICO', 'Resumen médico'],
    ];

    /**
     * El **sufijo del título** de los informes que lo llevan (WEB-6.2): el eco obstétrico no se llama sólo
     * «ECOSONOGRAFÍA OBSTÉTRICA», sino que dice el trimestre. En el escritorio el sufijo vive en el propio
     * DataWindow de impresión (`PRIMER TRIMESTRE` / `SEGUNDO Y TERCER TRIMESTRE`), así que la hoja **y su
     * formato en blanco** lo comparten desde acá: sin esto, los dos formatos en blanco del obstétrico
     * saldrían impresos con el mismo encabezado.
     *
     * @var array<string,string>
     */
    public const SUFIJOS = [
        self::ECO_OBSTETRICO => ' · PRIMER TRIMESTRE',
        self::ECO_OBSTETRICO_SEGUNDO => ' · SEGUNDO TRIMESTRE',
        self::ECO_OBSTETRICO_TERCERO => ' · TERCER TRIMESTRE',
    ];

    /** Lo que separa los elementos de un párrafo de lista (los exámenes de un tipo). */
    public const SEPARADOR_DE_ITEMS = ' · ';

    /** El título con el que sale impreso un documento: el suyo y, si lo lleva, su sufijo (el trimestre). */
    public static function tituloConSufijo(string $clave): string
    {
        return self::TITULOS[$clave][0] . (self::SUFIJOS[$clave] ?? '');
    }

    /**
     * @param  string  $titulo  lo que se imprime arriba del cuerpo (`CONSTANCIA`, `REPOSO MÉDICO`…)
     * @param  string  $nombre  cómo se llama en las listas (`Constancia`, `Reposo médico`…)
     * @param  string  $fecha  `AAAA-MM-DD`: la que sale en los datos del paciente
     * @param  array{nombre:string, cedula:?string, edad:?int, direccion:?string}  $persona
     * @param  array<int,array{etiqueta:string, valor:string}>  $fijos
     * @param  array<int,array{texto:string, etiqueta:?string, aparte:bool, items?:array<int,string>}>  $parrafos
     * @param  ?string  $para  el destinatario que sale **en la cabecera, en una caja con borde** (`informe.para`
     *                         del resumen médico, WEB-5.3c); en los demás documentos va en la cabecera del
     *                         paciente y no se imprime: `null`
     * @param  array<int,array{src:string, base?:?string, descripcion:?string}>  $imagenes  las imágenes que se
     *                         imprimen **en la misma página del documento**, debajo del texto (la colposcopía y la
     *                         histeroscopía, 2026-10-18). Un documento con `informe` las lleva adentro del informe.
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $clave,
        public readonly string $titulo,
        public readonly string $nombre,
        public readonly string $fecha,
        public readonly array $persona,
        public readonly array $fijos,
        public readonly array $parrafos,
        public readonly ?string $para = null,
        public readonly ?InformeDeEco $informe = null,
        public readonly array $imagenes = [],
    ) {
    }

    /**
     * **El modo de la hoja de este documento** (O-26): media hoja horizontal o A4 vertical completa.
     *
     * No se decide acá: el documento lo **declara** con su clave en `ModoDeLaHoja`, que es la única tabla de esa regla. Un
     * documento con `informe` (los ecos, WEB-11.13) va siempre a hoja completa, porque su informe es un formulario lleno y
     * ocupa una página entera.
     */
    public function modo(): string
    {
        return $this->informe !== null ? ModoDeLaHoja::COMPLETA : ModoDeLaHoja::de($this->tipo);
    }

    /** ¿Se imprime en hoja completa vertical (una por A4) y no en media hoja? */
    public function enHojaCompleta(): bool
    {
        return $this->modo() === ModoDeLaHoja::COMPLETA;
    }

    /** El mismo documento con su **informe a hoja completa** (WEB-11.13): una eco se imprime como un formulario lleno, no en mitades. */
    public function conInforme(InformeDeEco $informe): self
    {
        return new self($this->tipo, $this->clave, $this->titulo, $this->nombre, $this->fecha, $this->persona, $this->fijos, $this->parrafos, $this->para, $informe, $this->imagenes);
    }

    /**
     * El mismo documento con sus **imágenes para la hoja del informe** (A-13: los dibujos de la colposcopía y la
     * histeroscopía). Salen **en la misma página** que el texto, debajo de él (2026-10-18, como el subreporte del
     * escritorio): el reparto del texto les reserva su alto (`DistribuidorDeDocumento`).
     *
     * @param  array<int,array{src:string, base?:?string, descripcion:?string}>  $imagenes
     */
    public function conImagenes(array $imagenes): self
    {
        return new self($this->tipo, $this->clave, $this->titulo, $this->nombre, $this->fecha, $this->persona, $this->fijos, $this->parrafos, $this->para, $this->informe, $imagenes);
    }

    /** ¿Tiene imágenes propias para la hoja de su informe (la colposcopía y la histeroscopía)? */
    public function conImagenesDeLaHoja(): bool
    {
        return $this->imagenes !== [];
    }

    /**
     * Un párrafo de lista (WEB-5.4): un rótulo en su propia línea y debajo los elementos, unidos por ` · `. Se parte entre elementos, no por palabra.
     *
     * @param  array<int,string>  $items  cada elemento en una sola línea de texto (los espacios de más ya colapsados)
     * @return array{texto:string, etiqueta:string, aparte:bool, items:array<int,string>}
     */
    public static function parrafoDeLista(string $etiqueta, array $items): array
    {
        $items = array_values(array_map(fn (string $item) => trim((string) preg_replace('/\s+/u', ' ', $item)), $items));

        return ['texto' => implode(self::SEPARADOR_DE_ITEMS, $items), 'etiqueta' => $etiqueta, 'aparte' => true, 'items' => $items];
    }

    /**
     * El texto como párrafos: un renglón del texto es un párrafo (el médico los separa a propósito; las líneas en blanco
     * se descartan). El rótulo, si hay, va solo en el primero.
     *
     * @return array<int,array{texto:string, etiqueta:?string, aparte:bool}>
     */
    public static function parrafosDe(?string $texto, ?string $etiqueta = null, bool $aparte = false): array
    {
        $parrafos = [];

        foreach (preg_split('/\R/u', (string) $texto) ?: [] as $renglon) {
            $renglon = trim((string) preg_replace('/[ \t]+/u', ' ', $renglon));

            if ($renglon === '') {
                continue;
            }

            $parrafos[] = ['texto' => $renglon, 'etiqueta' => $parrafos === [] ? $etiqueta : null, 'aparte' => $parrafos === [] && $aparte];
        }

        return $parrafos;
    }
}
