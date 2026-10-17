<?php

namespace App\Clinica\Consulta;

use App\Clinica\Impresion\DocumentoImpreso;

/**
 * **El resumen médico de una consulta, ya armado** (WEB-5.3c): el ViewModel de la hoja que el escritorio
 * genera sola con los datos de **toda** la consulta (`d_resumen_medico_gra`, el radio «Resumen médico» de
 * la pestaña *Informe médico*, `w_consulta_principal.srw:18653`).
 *
 * No consulta la base —recibe lo ya leído por `ResumenMedicoDeLaConsulta`— y devuelve **lo que se
 * imprime**: los párrafos con sus rótulos literales, en el orden del DataWindow. Se prueba sin base de
 * datos.
 *
 * ## Qué lleva y qué no
 *
 * Los ocho bloques, en el orden del `d_resumen_medico_gra` y con el rótulo literal de cada subreporte:
 *
 * | # | Rótulo | Dato | Fuente |
 * |---|---|---|---|
 * | 1 | `MOTIVO DE CONSULTA:` | `motivos_consulta.descripcion` (vía `motivo_consulta_paciente`), `ORDER BY descripcion ASC` | `d_informe_motivo.srd` |
 * | 2 | `ENFERMEDAD ACTUAL:` | `consultas.enfermedadactual` | `d_enfermedad_actua_nuevo.srd` |
 * | 3 | `EXAMEN FÍSICO:` | `examen_fisico_nuevo.total_f` | `d_exa_fi_fisico_nuevo.srd` |
 * | 4 | `EXAMEN GINECOLÓGICO:` | `examen_fisico_nuevo.total_g` | `d_exa_fisico_ginecologico_reporte_nuev.srd` |
 * | 5 | `RESULTADO DE EXÁMENES:` | `consultas.oido` | `d_examenes_info_nuevo.srd` |
 * | 6 | `DIAGNOSTICO` (sin dos puntos) | `diagnostico_paciente.codediagnostico` por `orden`, con la descripción del catálogo | `d_lista_diagnostic01.srd` |
 * | 7 | `TRATAMIENTO:` | `vademecum.nombrecomercial + '/' + vademecum.presentacion` **+ las indicaciones** de cada medicamento de los `recipes` de la consulta | `d_tratamiento_consulta_reporte.srd` |
 * | 8 | `PLAN DE TRATAMIENTO:` | `consultas.laringe`: el texto libre del ítem **Tratamiento** (WEB-5.7) | `d_enfermedad_tratamirnto_nuevo.srd` |
 *
 * **No entra** lo que el DataWindow tampoco imprime: los antecedentes, la evolución, los ecos (pélvico,
 * obstétrico, Doppler, mamario, abdominal), el control prenatal, el laboratorio y los estudios, las
 * imágenes, el texto de `informe.descripcion` y los signos vitales.
 *
 * ## El tratamiento (2026-10-15)

`consultas.laringe` es, en el escritorio, el texto libre de su ítem TRATAMIENTO (un apartado distinto de los récipes,
confirmado por Alexander). La web lo tiene desde WEB-5.7 (`?seccion=tratamiento`) y es el bloque 8, «PLAN DE
TRATAMIENTO». El bloque 7 sigue calculando, de los récipes, cada medicamento con sus indicaciones: son dos cosas, el
plan que escribe el médico y lo que recetó.

## Las tres decisiones de la web (no las dice la fuente)
 *
 *  - **Un bloque sin datos no se imprime.** El escritorio imprime el rótulo igual (`band=header` con el
 *    `text` fijo) y el subreporte sale vacío; la web no imprime rótulos huérfanos, que es el mismo
 *    criterio del resumen de historia clínica (`ResumenDeHistoriaVista`).
 *  - **La hoja se ofrece sólo si hay algo que resumir** y, para eso, **el tratamiento solo no alcanza**
 *    (`esDocumento`): los medicamentos ya tienen su récipe.
 *  - **Los rótulos son los del resumen médico, no los de la historia clínica.** Son dos resúmenes
 *    distintos del legado: en `d_historia_clinica_resumen` esas mismas columnas salen como «Oído» y
 *    «Laringe»; acá, «RESULTADO DE EXÁMENES» y «PLAN DE TRATAMIENTO».
 *
 * La **hoja** es la media hoja A4 del resto de los documentos (decisión 17 de `TODO-WEB.md`): del `.srd`
 * se lee **qué datos** lleva, no se copia su layout.
 */
final class ResumenMedico
{
    public const MOTIVO_DE_CONSULTA = 'motivo-de-consulta';

    public const ENFERMEDAD_ACTUAL = 'enfermedad-actual';

    public const EXAMEN_FISICO = 'examen-fisico';

    public const EXAMEN_GINECOLOGICO = 'examen-ginecologico';

    public const RESULTADO_DE_EXAMENES = 'resultado-de-examenes';

    public const DIAGNOSTICO = 'diagnostico';

    public const TRATAMIENTO = 'tratamiento';

    public const PLAN_DE_TRATAMIENTO = 'plan-de-tratamiento';

    /**
     * Los bloques en el orden en que se imprimen, con su rótulo literal y su forma: `lista` son los que el
     * escritorio imprime como un renglón por elemento (los motivos, los diagnósticos y los medicamentos) y
     * el resto son un texto.
     *
     * @var array<string,array{rotulo:string, lista:bool}>
     */
    private const BLOQUES = [
        self::MOTIVO_DE_CONSULTA => ['rotulo' => 'MOTIVO DE CONSULTA:', 'lista' => true],
        self::ENFERMEDAD_ACTUAL => ['rotulo' => 'ENFERMEDAD ACTUAL:', 'lista' => false],
        self::EXAMEN_FISICO => ['rotulo' => 'EXAMEN FÍSICO:', 'lista' => false],
        self::EXAMEN_GINECOLOGICO => ['rotulo' => 'EXAMEN GINECOLÓGICO:', 'lista' => false],
        self::RESULTADO_DE_EXAMENES => ['rotulo' => 'RESULTADO DE EXÁMENES:', 'lista' => false],
        // El único rótulo sin dos puntos: así está en el `text` del DataWindow (`d_lista_diagnostic01.srd`).
        self::DIAGNOSTICO => ['rotulo' => 'DIAGNOSTICO', 'lista' => true],
        self::TRATAMIENTO => ['rotulo' => 'TRATAMIENTO:', 'lista' => true],
        self::PLAN_DE_TRATAMIENTO => ['rotulo' => 'PLAN DE TRATAMIENTO:', 'lista' => false],
    ];

    /**
     * @param  array<string,mixed>  $valores  el dato de cada bloque, ya leído: un texto (una o más líneas) o una lista de
     *                                        elementos. Lo que no esté, o venga vacío, no se imprime.
     * @param  ?string  $para  `informe.para`, el destinatario que sale en la cabecera
     * @param  ?string  $fecha  `informe.fe_cha` (AAAA-MM-DD), la fecha de la cabecera
     */
    public function __construct(
        private array $valores = [],
        private ?string $para = null,
        private ?string $fecha = null,
    ) {
    }

    public function para(): ?string
    {
        return $this->para;
    }

    public function fecha(): ?string
    {
        return $this->fecha;
    }

    /**
     * ¿La consulta tiene algo que **resumir**? Es la regla de «qué es un documento» de esta hoja, y la
     * decide la web (el legado no la dice: su radio siempre está disponible).
     *
     * Hace falta que algún bloque **distinto del tratamiento** tenga datos. El tratamiento es un bloque de
     * la hoja —se imprime cuando la hoja sale, con los medicamentos de los récipes—, pero **no alcanza
     * solo** para ofrecerla: los medicamentos ya tienen su documento (el récipe) y un resumen cuyo único
     * renglón es lo recetado no le dice nada a nadie. Es la misma vara con la que la historia descarta las
     * plantillas vacías del escritorio (`QueEsUnDocumento`), y la que deja como estaba la lista de la
     * secretaría: con un solo documento, «Imprimir documentos» se salta la lista y va derecho a la hoja.
     */
    public function esDocumento(): bool
    {
        foreach (self::BLOQUES as $clave => $bloque) {
            if ($clave !== self::TRATAMIENTO && $this->parrafosDelBloque($clave, $bloque) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * El cuerpo de la hoja: un párrafo por bloque con datos, en el orden del DataWindow. Los bloques de
     * lista salen como un párrafo de lista (`DocumentoImpreso::parrafoDeLista`: se parte entre elementos y
     * nunca por palabra) y los de texto, como el informe (un párrafo por renglón escrito).
     *
     * @return array<int,array{texto:string, etiqueta:?string, aparte:bool, items?:array<int,string>}>
     */
    public function parrafos(): array
    {
        $parrafos = [];

        foreach (self::BLOQUES as $clave => $bloque) {
            foreach ($this->parrafosDelBloque($clave, $bloque) as $parrafo) {
                $parrafos[] = $parrafo;
            }
        }

        return $parrafos;
    }

    /** Los párrafos de un bloque, o `[]` si no tiene datos (es lo que hace que su rótulo no se imprima). */
    private function parrafosDelBloque(string $clave, array $bloque): array
    {
        if ($bloque['lista']) {
            $items = $this->elementos($this->valores[$clave] ?? null);

            return $items === [] ? [] : [DocumentoImpreso::parrafoDeLista($bloque['rotulo'], $items)];
        }

        return DocumentoImpreso::parrafosDe($this->texto($this->valores[$clave] ?? null), $bloque['rotulo'], true);
    }

    /** Los rótulos de los bloques que tienen datos, para las pruebas y para quien quiera contarlos. */
    public function rotulos(): array
    {
        $rotulos = [];

        foreach ($this->parrafos() as $parrafo) {
            if ($parrafo['etiqueta'] !== null) {
                $rotulos[] = $parrafo['etiqueta'];
            }
        }

        return $rotulos;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * El texto de un bloque: el que se va a imprimir, con los "vacíos" del legado (`none`) ya leídos como
     * «sin texto» (`TextoDeLaConsulta::limpio`). Una lista llega a `texto()` sólo si algún llamador la
     * pasó a un bloque de texto: sus elementos se unen en renglones.
     */
    private function texto($valor): ?string
    {
        if (is_array($valor)) {
            $valor = implode("\n", array_map('strval', $valor));
        }

        return TextoDeLaConsulta::limpio($valor);
    }

    /**
     * Los elementos de un bloque de lista: cada uno en **una sola línea** (los espacios de más se
     * colapsan, como en `DocumentoImpreso::parrafoDeLista`) y los que no dicen nada se descartan.
     *
     * @return array<int,string>
     */
    private function elementos($valor): array
    {
        if ($valor === null) {
            return [];
        }

        $crudos = is_array($valor) ? $valor : (preg_split('/\R/u', (string) $valor) ?: []);

        $items = [];

        foreach ($crudos as $crudo) {
            $item = $this->texto($crudo);

            if ($item !== null) {
                $items[] = (string) preg_replace('/\s+/u', ' ', $item);
            }
        }

        return $items;
    }
}
