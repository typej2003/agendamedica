<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\ReferenciasDeLaConsulta;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Clinica\Embarazo\InformePrenatal;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PrenaExamenB;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Models\Consulta;
use App\Models\Paciente;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * **Las solicitudes de una consulta, listas para imprimir** (WEB-5.3a, WEB-5.3b, WEB-5.4): qué hay para imprimir (laboratorio, estudios,
 * constancia, reposo, referencias, hospitalización, informe y dieta) y cada una armada como `DocumentoImpreso`.
 *
 * No decide qué es un documento ni lee nada por su cuenta: usa las lecturas de la sección de solicitudes
 * (`ConstanciaDeLaConsulta`, `ReposoDeLaConsulta`, `ReferenciasDeLaConsulta`, `InformeDeLaConsulta`) y la regla de
 * `QueEsUnDocumento`, que son **la** definición de cada documento. Lo que agrega es lo que sólo la hoja necesita: el
 * texto fijo, los rótulos y la cabecera del paciente.
 *
 * ## Qué sale en cada hoja (dato y regla del escritorio, no su pantalla: decisión 17 del plan)
 *
 *  - **Constancia** (`d_constancia.srd`): el texto base con el paciente y el día de la consulta, *IDX:* con los diagnósticos y
 *    la observación. Fecha de la cabecera: la de la consulta.
 *  - **Reposo** (`d_reposo_1.srd`): *REPOSO DURANTE*, *A PARTIR DE LA FECHA*, *HASTA EL DÍA* (inicio + días − 1) y *REINTEGRO A
 *    SUS LABORES EL DÍA* (inicio + días), y la justificación. Fecha de la cabecera: la de inicio del reposo.
 *  - **Referencia** (`d_referencia_2.srd`): *REFERIDO A*, *ESPECIALIDAD*, *CLÍNICA*, *DIRECCIÓN*, *TELÉFONO* y *MOTIVO DE LA
 *    REFERENCIA*. Se omiten los datos del médico que no están cargados en vez de imprimir rayas.
 *  - **Informe** (`d_informe_medico_ver_media`): *PARA* y el texto. Fecha de la cabecera: la del informe o, si no tiene, la
 *    de la consulta.
 *  - **Hospitalización** (`d_hospitalizacion_1.srd`, *ORDEN DE HOSPITALIZACIÓN*): *Hospitalizar en*, *Diagnóstico provisional* y
 *    *Recomendaciones*. La hoja del escritorio trae también `indiprev` y `habitacion` en su consulta pero **no las imprime**. Fecha de la
 *    cabecera: la de la consulta. Se omite lo que no está escrito en vez de imprimir rótulos vacíos.
 *  - **Dieta** (`d_recipe_dieta.srd`, *RECOMENDACIONES / DIETAS*): el título de la dieta (`dieta`, en negrita) y su texto. Fecha de la
 *    cabecera: la de la consulta.
 *  - **Laboratorio** (`d_examen_laboratorio_rut.srd`, la hoja de las *rutinas*; la de exámenes sueltos, `d_examen_laboratorio.srd`, es la misma sin
 *    agrupar): *EXÁMENES DE LABORATORIO*, los exámenes **agrupados por tipo** (`group by tipos_examenes_tipo`) y el *Resumen clínico*
 *    (`examen_obs.observacion`). Acá el título es «SOLICITUD DE EXÁMENES DE LABORATORIO», el grupo es un párrafo de lista (los exámenes del tipo, uno
 *    tras otro, `DocumentoImpreso::parrafoDeLista`) y el resumen sale como *RESUMEN CLÍNICO:*. Fecha de la cabecera: la de la consulta.
 *  - **Estudios** (`d_estudio_imagenes.srd`, *SOLICITUD DE ESTUDIOS*): los estudios en el orden de `radiologia_paciente.orden`, numerados
 *    («Estudios solicitados:»), y el *Resumen clínico* (`radiologia_obs.observacion`). Fecha: la de la consulta.
 *
 *  «Resumen clínico» es sólo el **rótulo de la observación** de esa orden (el campo que el médico escribe), no un texto que el escritorio arme
 *  con los diagnósticos: la web no agrega ninguna línea automática.
 *
 * ## Qué se ofrece
 *
 * Sólo los **documentos de verdad** (`QueEsUnDocumento`): nada de las plantillas vacías del escritorio. La **constancia**
 * es la excepción controlada: su texto base existe para toda consulta, así que el médico puede imprimirla siempre
 * (`$constanciaBase`), pero sólo se *ofrece* —en la agenda, a la secretaría— cuando el médico escribió algo propio.
 *
 * Es de **sólo lectura**.
 */
final class SolicitudesParaImprimir
{
    public function __construct(
        private ConstanciaDeLaConsulta $constancias,
        private ReposoDeLaConsulta $reposos,
        private ReferenciasDeLaConsulta $referencias,
        private InformeDeLaConsulta $informes,
        private HospitalizacionDeLaConsulta $hospitalizaciones,
        private DietaDeLaConsulta $dietas,
        private LaboratorioDeLaConsulta $laboratorios,
        private EstudiosDeLaConsulta $estudios,
        private EcoGinecologico $ecos,
        private EcoObstetrico $ecosObstetricos,
        private InformePrenatal $informesPrenatales,
    ) {
    }

    /**
     * Lo que se puede imprimir de la consulta, en el orden de las pestañas.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico (para resolver al médico referido)
     * @param  bool  $constanciaBase  `true` ofrece la constancia aunque no tenga nada propio (lo que ve el médico)
     * @return array<int,array{clave:string, tipo:string, titulo:string, resumen:string, fecha:?string}>
     */
    public function disponibles(Consulta $consulta, array $registros, bool $constanciaBase = false): array
    {
        $disponibles = [];
        $fechaDeLaConsulta = $this->fechaDeLaConsulta($consulta);

        // El informe del estudio ecográfico (WEB-6.1, ítem 5 del panel de la consulta). Va primero: es el
        // diagnóstico del estudio, no una solicitud.
        $estudio = $this->ecos->de($consulta);
        if ($this->elEcoEsDocumento($estudio)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_PELVICO,
                'tipo' => DocumentoImpreso::ECO_PELVICO,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_PELVICO][1],
                // Sin los hallazgos ni las conclusiones: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del eco pélvico',
                // La fecha de la hoja es la de la consulta; la del estudio va como dato del informe.
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // El informe del control de embarazo (WEB-6.6): es de la **gesta** y no de la consulta, así que se
        // ofrece si esa historia tiene alguna gesta (sin ninguna no hay nada que imprimir). La hoja sale con
        // la gesta actual; el `&gesta=` de la URL permite imprimir otra.
        if ($this->informesPrenatales->tieneInforme((string) $consulta->reg_medico, (int) $consulta->numhistoria)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::CONTROL_DE_EMBARAZO,
                'tipo' => DocumentoImpreso::CONTROL_DE_EMBARAZO,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::CONTROL_DE_EMBARAZO][1],
                // Sin los controles ni el laboratorio: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del control de embarazo',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // El informe del eco obstétrico del primer trimestre (WEB-6.2): se ofrece si el estudio tiene datos
        // (la fecha sola no lo hace, igual que en el eco pélvico).
        $estudioObstetrico = $this->ecosObstetricos->de($consulta);
        if ($this->elEcoObstetricoEsDocumento($estudioObstetrico)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_OBSTETRICO,
                'tipo' => DocumentoImpreso::ECO_OBSTETRICO,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_OBSTETRICO][1],
                // Sin los hallazgos ni la conclusión: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del eco obstétrico (1.er trimestre)',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        $examenes = $this->laboratorios->filas($consulta);
        if ($this->laboratorios->esReal($examenes, $this->laboratorios->observacion($consulta))) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::LABORATORIO,
                'tipo' => DocumentoImpreso::LABORATORIO,
                'titulo' => 'Laboratorio',
                // Sin los exámenes ni el resumen: la lista de la cita la ve la secretaría y no lleva contenido clínico.
                'resumen' => 'Orden de laboratorio',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        $pedidos = $this->estudios->filas($consulta);
        if ($this->estudios->esReal($pedidos, $this->estudios->observacion($consulta))) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ESTUDIOS,
                'tipo' => DocumentoImpreso::ESTUDIOS,
                'titulo' => 'Estudios',
                'resumen' => 'Solicitud de estudios',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        $constancia = $this->constancias->fila($consulta);
        if ($constanciaBase || $this->constancias->tieneContenidoPropio($constancia)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::CONSTANCIA,
                'tipo' => DocumentoImpreso::CONSTANCIA,
                'titulo' => 'Constancia',
                'resumen' => 'Constancia de asistencia',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        $reposo = $this->reposos->fila($consulta);
        if ($this->reposos->esReal($reposo)) {
            $dias = (int) $reposo->numdias;
            $disponibles[] = [
                'clave' => DocumentoImpreso::REPOSO,
                'tipo' => DocumentoImpreso::REPOSO,
                'titulo' => 'Reposo médico',
                'resumen' => $dias . ($dias === 1 ? ' día' : ' días'),
                'fecha' => $this->fechaDelReposo($reposo, $fechaDeLaConsulta),
            ];
        }

        foreach ($this->referencias->deLaConsulta($consulta, $registros) as $referencia) {
            $medico = $referencia->medico;
            $disponibles[] = [
                'clave' => DocumentoImpreso::REFERENCIA . '-' . $referencia->cedula,
                'tipo' => DocumentoImpreso::REFERENCIA,
                'titulo' => 'Referencia',
                'resumen' => 'Referido a ' . ($medico->nombre ?? 'Médico ' . $referencia->cedula)
                    . (($medico->especialidad ?? null) ? ' · ' . $medico->especialidad : ''),
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        $hospitalizacion = $this->hospitalizaciones->fila($consulta);
        if ($this->hospitalizaciones->esReal($hospitalizacion)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::HOSPITALIZACION,
                'tipo' => DocumentoImpreso::HOSPITALIZACION,
                'titulo' => 'Hospitalización',
                // Sin el lugar ni el diagnóstico: la lista de la cita la ve la secretaría y no lleva contenido clínico.
                'resumen' => 'Orden de hospitalización',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        $informe = $this->informes->fila($consulta);
        if ($this->informes->esReal($informe)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::INFORME,
                'tipo' => DocumentoImpreso::INFORME,
                'titulo' => 'Informe médico',
                'resumen' => 'Informe médico',
                'fecha' => $informe->fe_cha !== null ? $informe->fe_cha->toDateString() : $fechaDeLaConsulta,
            ];
        }

        $dieta = $this->dietas->fila($consulta);
        if ($this->dietas->esReal($dieta)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::DIETA,
                'tipo' => DocumentoImpreso::DIETA,
                'titulo' => 'Dieta / Recomendaciones',
                // Sin el título de la dieta (dice de qué se trata): la lista de la cita no lleva contenido clínico.
                'resumen' => 'Dieta / Recomendaciones',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        return $disponibles;
    }

    /**
     * El documento pedido por su clave (`constancia`, `reposo`, `referencia-3`, `informe`), o `null` si la consulta no lo
     * tiene. La constancia siempre se puede armar (su texto base existe para toda consulta); quien llama decide si la
     * ofrece (`disponibles`).
     *
     * @param  array<int,string>  $registros
     */
    public function documento(Consulta $consulta, int $pacienteId, array $registros, string $clave, ?int $gesta = null): ?DocumentoImpreso
    {
        $paciente = Paciente::find($pacienteId);

        if ($paciente === null) {
            return null;
        }

        $fechaDeLaConsulta = $this->fechaDeLaConsulta($consulta);

        if ($clave === DocumentoImpreso::CONSTANCIA) {
            return $this->constancia($consulta, $paciente, $fechaDeLaConsulta);
        }

        if ($clave === DocumentoImpreso::ECO_PELVICO) {
            $estudio = $this->ecos->de($consulta);

            return $this->elEcoEsDocumento($estudio) ? $this->ecoPelvico($paciente, $estudio, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::ECO_OBSTETRICO) {
            $estudio = $this->ecosObstetricos->de($consulta);

            return $this->elEcoObstetricoEsDocumento($estudio) ? $this->ecoObstetrico($paciente, $estudio, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::CONTROL_DE_EMBARAZO) {
            // El informe es de una **gesta** (la pedida en la URL o la actual), no de la consulta: es lo
            // único de esta sección que no cuelga de la consulta.
            $partes = $this->informesPrenatales->de((string) $consulta->reg_medico, (int) $consulta->numhistoria, $gesta);

            return $partes === null ? null : $this->informeDelEmbarazo($paciente, $partes, $fechaDeLaConsulta);
        }

        if ($clave === DocumentoImpreso::LABORATORIO) {
            $examenes = $this->laboratorios->filas($consulta);
            $resumen = $this->laboratorios->observacion($consulta);

            return $this->laboratorios->esReal($examenes, $resumen) ? $this->laboratorio($paciente, $examenes, $resumen, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::ESTUDIOS) {
            $pedidos = $this->estudios->filas($consulta);
            $resumen = $this->estudios->observacion($consulta);

            return $this->estudios->esReal($pedidos, $resumen) ? $this->estudio($paciente, $pedidos, $resumen, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::REPOSO) {
            $reposo = $this->reposos->fila($consulta);

            return $this->reposos->esReal($reposo) ? $this->reposo($paciente, $reposo, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::INFORME) {
            $informe = $this->informes->fila($consulta);

            return $this->informes->esReal($informe) ? $this->informe($paciente, $informe, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::HOSPITALIZACION) {
            $orden = $this->hospitalizaciones->fila($consulta);

            return $this->hospitalizaciones->esReal($orden) ? $this->hospitalizacion($paciente, $orden, $fechaDeLaConsulta) : null;
        }

        if ($clave === DocumentoImpreso::DIETA) {
            $dieta = $this->dietas->fila($consulta);

            return $this->dietas->esReal($dieta) ? $this->dieta($paciente, $dieta, $fechaDeLaConsulta) : null;
        }

        if (preg_match('/^' . DocumentoImpreso::REFERENCIA . '-(\d+)$/', $clave, $coincidencia) === 1) {
            $referencia = $this->referencias->deLaConsulta($consulta, $registros)
                ->first(fn (object $referencia) => $referencia->cedula === (int) $coincidencia[1]);

            return $referencia === null ? null : $this->referencia($paciente, $referencia, $fechaDeLaConsulta);
        }

        return null;
    }

    // ------------------------------------------------------------------------------------- Cada documento

    private function constancia(Consulta $consulta, Paciente $paciente, string $fecha): DocumentoImpreso
    {
        $fila = $this->constancias->fila($consulta);
        $nombre = PacienteImpreso::nombre($paciente);

        $parrafos = DocumentoImpreso::parrafosDe(ConstanciaDeLaConsulta::textoBase($nombre, FechaClinica::formato($fecha)));

        $parrafos = array_merge(
            $parrafos,
            DocumentoImpreso::parrafosDe($this->constancias->diagnosticos($consulta, $fila), 'IDX:'),
            DocumentoImpreso::parrafosDe($this->constancias->observacion($fila)),
        );

        return new DocumentoImpreso(
            DocumentoImpreso::CONSTANCIA,
            DocumentoImpreso::CONSTANCIA,
            DocumentoImpreso::TITULOS[DocumentoImpreso::CONSTANCIA][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::CONSTANCIA][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            [],
            $parrafos,
        );
    }

    private function reposo(Paciente $paciente, object $reposo, string $fechaDeLaConsulta): DocumentoImpreso
    {
        $dias = (int) $reposo->numdias;
        $desde = $this->fechaDelReposo($reposo, $fechaDeLaConsulta);
        $inicio = Carbon::parse($desde)->startOfDay();

        return new DocumentoImpreso(
            DocumentoImpreso::REPOSO,
            DocumentoImpreso::REPOSO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::REPOSO][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::REPOSO][1],
            $desde,
            PacienteImpreso::datos($paciente, $desde),
            [
                ['etiqueta' => 'REPOSO DURANTE:', 'valor' => $dias . ' día(s)'],
                ['etiqueta' => 'A PARTIR DE LA FECHA:', 'valor' => FechaClinica::formato($desde)],
                ['etiqueta' => 'HASTA EL DÍA:', 'valor' => FechaClinica::formato(ReposoDeLaConsulta::hasta($inicio, $dias)->toDateString())],
                ['etiqueta' => 'REINTEGRO A SUS LABORES EL DÍA:', 'valor' => FechaClinica::formato(ReposoDeLaConsulta::reintegro($inicio, $dias)->toDateString())],
            ],
            DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($reposo->obser_reposo)),
        );
    }

    private function referencia(Paciente $paciente, object $referencia, string $fecha): DocumentoImpreso
    {
        $medico = $referencia->medico;

        $fijos = [['etiqueta' => 'REFERIDO A:', 'valor' => $medico->nombre ?? 'Médico ' . $referencia->cedula]];

        foreach ([
            'ESPECIALIDAD:' => $medico->especialidad ?? null,
            'CLÍNICA:' => $medico->clinica ?? null,
            'DIRECCIÓN:' => $medico->direccion ?? null,
            'TELÉFONO:' => $medico->telefono ?? null,
        ] as $etiqueta => $valor) {
            if ($valor !== null) {
                $fijos[] = ['etiqueta' => $etiqueta, 'valor' => (string) preg_replace('/\s+/u', ' ', $valor)];
            }
        }

        return new DocumentoImpreso(
            DocumentoImpreso::REFERENCIA,
            DocumentoImpreso::REFERENCIA . '-' . $referencia->cedula,
            DocumentoImpreso::TITULOS[DocumentoImpreso::REFERENCIA][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::REFERENCIA][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $fijos,
            DocumentoImpreso::parrafosDe($referencia->motivo, 'MOTIVO DE LA REFERENCIA:', true),
        );
    }

    private function informe(Paciente $paciente, object $informe, string $fechaDeLaConsulta): DocumentoImpreso
    {
        $fecha = $informe->fe_cha !== null ? $informe->fe_cha->toDateString() : $fechaDeLaConsulta;
        $para = TextoDeLaConsulta::limpio($informe->para);

        return new DocumentoImpreso(
            DocumentoImpreso::INFORME,
            DocumentoImpreso::INFORME,
            DocumentoImpreso::TITULOS[DocumentoImpreso::INFORME][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::INFORME][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $para === null ? [] : [['etiqueta' => 'PARA:', 'valor' => $para]],
            DocumentoImpreso::parrafosDe($informe->descripcion),
        );
    }

    private function hospitalizacion(Paciente $paciente, object $orden, string $fecha): DocumentoImpreso
    {
        $lugar = $this->lugar($orden);

        return new DocumentoImpreso(
            DocumentoImpreso::HOSPITALIZACION,
            DocumentoImpreso::HOSPITALIZACION,
            DocumentoImpreso::TITULOS[DocumentoImpreso::HOSPITALIZACION][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::HOSPITALIZACION][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $lugar === null ? [] : [['etiqueta' => 'HOSPITALIZAR EN:', 'valor' => $lugar]],
            array_merge(
                DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($orden->diagprev), 'DIAGNÓSTICO PROVISIONAL:', true),
                DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($orden->dieta), 'RECOMENDACIONES:', true),
            ),
        );
    }

    /**
     * La orden de laboratorio: los exámenes agrupados por tipo (el orden de `filas`: por tipo y por nombre, los que no tienen tipo al final) y el
     * resumen clínico. Cada grupo es un párrafo de lista, que sólo se corta entre exámenes.
     *
     * @param  Collection<int,object>  $examenes
     */
    private function laboratorio(Paciente $paciente, Collection $examenes, ?string $resumen, string $fecha): DocumentoImpreso
    {
        $parrafos = [];

        foreach ($examenes->groupBy(fn (object $examen) => $examen->tipo ?? CatalogoParaPedirExamenes::SIN_TIPO) as $tipo => $delTipo) {
            $parrafos[] = DocumentoImpreso::parrafoDeLista(mb_strtoupper((string) $tipo) . ':', $delTipo->pluck('nombre')->map(fn ($nombre) => (string) $nombre)->all());
        }

        return new DocumentoImpreso(
            DocumentoImpreso::LABORATORIO,
            DocumentoImpreso::LABORATORIO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::LABORATORIO][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::LABORATORIO][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            [],
            array_merge($parrafos, DocumentoImpreso::parrafosDe($resumen, 'RESUMEN CLÍNICO:', true)),
        );
    }

    /**
     * La solicitud de estudios: uno por renglón, numerados en el orden de la lista, y el resumen clínico.
     *
     * @param  Collection<int,object>  $estudios
     */
    private function estudio(Paciente $paciente, Collection $estudios, ?string $resumen, string $fecha): DocumentoImpreso
    {
        $parrafos = [];

        foreach ($estudios->values() as $posicion => $estudio) {
            $parrafos[] = [
                'texto' => ($posicion + 1) . '. ' . $estudio->nombre,
                'etiqueta' => $posicion === 0 ? 'ESTUDIOS SOLICITADOS:' : null,
                'aparte' => $posicion === 0,
            ];
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ESTUDIOS,
            DocumentoImpreso::ESTUDIOS,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ESTUDIOS][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::ESTUDIOS][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            [],
            array_merge($parrafos, DocumentoImpreso::parrafosDe($resumen, 'RESUMEN CLÍNICO:', true)),
        );
    }

    private function dieta(Paciente $paciente, object $dieta, string $fecha): DocumentoImpreso
    {
        $titulo = TextoDeLaConsulta::limpio($dieta->dieta);

        return new DocumentoImpreso(
            DocumentoImpreso::DIETA,
            DocumentoImpreso::DIETA,
            DocumentoImpreso::TITULOS[DocumentoImpreso::DIETA][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::DIETA][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $titulo === null ? [] : [['etiqueta' => 'DIETA:', 'valor' => (string) preg_replace('/\s+/u', ' ', $titulo)]],
            DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($dieta->descripcion)),
        );
    }

    /**
     * El **informe del eco pélvico** (WEB-6.1): los datos del estudio, el útero, los ovarios, los hallazgos
     * y las conclusiones, en el orden del informe del escritorio (`d_eco_pelvico_print.srd`).
     *
     * Los rótulos, los códigos de los desplegables y las unidades salen del **mapa del dato**
     * (`EcoGinecologico`), que es el mismo que pinta el formulario: un campo nuevo aparece en la pantalla y
     * en la hoja sin tocar esto. Los bloques cortos van como párrafo con su rótulo en la misma línea; los
     * textos largos (anexos, hallazgos, observaciones y conclusiones) con el rótulo arriba, para que se
     * lean como se escriben. Un bloque sin ningún dato no se imprime.
     */
    private function ecoPelvico(Paciente $paciente, EstudioEcografico $estudio, string $fecha): DocumentoImpreso
    {
        $fijos = [];

        foreach (['fur' => 'FUR:', 'gesta' => 'Gesta:', 'PARA' => 'PARA:'] as $columna => $etiqueta) {
            $valor = $estudio->valor($columna);

            if ($valor !== null) {
                $fijos[] = [
                    'etiqueta' => $etiqueta,
                    'valor' => $columna === EcoGinecologico::COLUMNA_FUR
                        ? FechaClinica::formato($valor)
                        : (string) EcoGinecologico::textoDe($columna, $valor),
                ];
            }
        }

        // La fecha del estudio sólo se imprime si no es la de la consulta: la de la cabecera es la de la
        // consulta, como en el escritorio (`d_eco_pelvico_print.srd` imprime `consultas.fecha`).
        $fechaDelEstudio = $estudio->fecha();
        if ($fechaDelEstudio !== null && $fechaDelEstudio !== $fecha) {
            $fijos[] = ['etiqueta' => 'Estudio del:', 'valor' => FechaClinica::formato($fechaDelEstudio)];
        }

        $parrafos = [];

        $this->sumarParrafo($parrafos, 'Transductor:', $this->linea([
            $this->datoDelEstudio($estudio, 'trans_convex'),
            $this->datoDelEstudio($estudio, 'trans_transvaginal'),
        ]));

        $this->sumarParrafo($parrafos, 'Vejiga:', $this->linea([
            $this->datoDelEstudio($estudio, 'vagina'),
            $this->datoDelEstudio($estudio, 'vagina_llena'),
        ]));

        // ÚTERO: posición (con su lado), forma, borde y patrón miometrial.
        $this->sumarParrafo($parrafos, 'Posición:', $this->linea([
            $this->datoDelEstudio($estudio, 'posicionavf'),
            $this->datoDelEstudio($estudio, 'posicionavf_d'),
            $this->datoDelEstudio($estudio, 'posicionrvf_d'),
        ]));
        $this->sumarParrafo($parrafos, 'Forma:', $this->datoDelEstudio($estudio, 'fondosacolibre'));
        $this->sumarParrafo($parrafos, 'Borde:', $this->datoDelEstudio($estudio, 'bordeutero'));
        $this->sumarParrafo($parrafos, 'Patrón miometrial:', $this->datoDelEstudio($estudio, 'patron'));

        // Las medidas del cuerpo uterino, con su unidad.
        $this->sumarParrafo($parrafos, 'Medidas:', $this->linea([
            $this->datoDelEstudio($estudio, 'cuerpo'),
            $this->datoDelEstudio($estudio, 'transverso'),
            $this->datoDelEstudio($estudio, 'antero'),
        ]));

        $this->sumarParrafo($parrafos, 'Endometrio:', $this->linea([
            $this->datoDelEstudio($estudio, 'linea'),
            $this->datoDelEstudio($estudio, 'linea_continuo'),
            $this->datoDelEstudio($estudio, 'linea_homo'),
            $this->datoDelEstudio($estudio, 'espesor'),
        ]));

        $this->sumarParrafo($parrafos, 'Cavidad uterina:', $this->datoDelEstudio($estudio, 'linea_cavidad_u'));
        $this->sumarParrafo($parrafos, 'Fondo de saco de Douglas:', $this->datoDelEstudio($estudio, 'fondosacootro'));

        // Anexos y los dos textos del final: largos, con el rótulo en su propia línea.
        foreach (DocumentoImpreso::parrafosDe($estudio->valor('observacion'), 'Anexos:', true) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        foreach (['der' => 'Ovario derecho:', 'izq' => 'Ovario izquierdo:'] as $lado => $etiqueta) {
            $this->sumarParrafo($parrafos, $etiqueta, $this->linea([
                $this->datoDelEstudio($estudio, 'ovario' . $lado . 'long'),
                $this->datoDelEstudio($estudio, 'ovario' . $lado . 'ap'),
                $this->datoDelEstudio($estudio, 'ovario' . $lado . 'tras'),
                $this->datoDelEstudio($estudio, 'volumen_' . $lado),
            ]));
        }

        foreach ([
            'observacionovario' => 'Otros hallazgos:',
            'idxecografico' => 'Observaciones:',
            'sugerencias' => 'Conclusiones:',
        ] as $columna => $etiqueta) {
            foreach (DocumentoImpreso::parrafosDe($estudio->valor($columna), $etiqueta, true) as $parrafo) {
                $parrafos[] = $parrafo;
            }
        }

        // El modo "puro texto" del escritorio: si dejó un informe escrito así, se imprime tal cual.
        foreach (DocumentoImpreso::parrafosDe($estudio->valor(EcoGinecologico::COLUMNA_PURO_TEXTO), 'Informe en texto libre:', true) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ECO_PELVICO,
            DocumentoImpreso::ECO_PELVICO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_PELVICO][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_PELVICO][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $fijos,
            $parrafos,
        );
    }

    /**
     * El dato de una columna del estudio con su rótulo y su unidad ("Cuerpo (longitud) 62,5 mm"), o `null`
     * si no hay nada cargado. El rótulo se puede pasar para el caso en que el de la pantalla no sea el que
     * conviene en la hoja.
     */
    private function datoDelEstudio(EstudioEcografico $estudio, string $columna, ?string $etiqueta = null): ?string
    {
        $valor = $estudio->valor($columna);

        if ($valor === null) {
            return null;
        }

        $texto = (string) (EcoGinecologico::textoDe($columna, $valor) ?? $valor);
        $unidad = $this->unidadDe($columna);

        return trim(($etiqueta ?? EcoGinecologico::etiquetaDe($columna)) . ' ' . $texto . ($unidad === null ? '' : ' ' . $unidad));
    }

    /** La unidad de una columna del estudio, del mapa del dato (la que imprime el informe del escritorio). */
    private function unidadDe(string $columna): ?string
    {
        foreach (EcoGinecologico::campos() as $campo) {
            if ($campo['columna'] === $columna) {
                return $campo['unidad'];
            }
        }

        return null;
    }

    /** Las partes con algo, unidas por ` · `; `null` si no hay ninguna. */
    private function linea(array $partes): ?string
    {
        $partes = array_values(array_filter($partes, fn ($parte) => $parte !== null && $parte !== ''));

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /** Suma un párrafo con su rótulo en la misma línea, si hay texto. */
    private function sumarParrafo(array &$parrafos, string $etiqueta, ?string $texto): void
    {
        if ($texto === null || $texto === '') {
            return;
        }

        $parrafos[] = ['texto' => $texto, 'etiqueta' => $etiqueta, 'aparte' => false];
    }

    /** ¿El estudio es un documento de verdad? La misma regla que usa la pantalla (`QueEsUnDocumento::ecografia`). */
    /**
     * **El informe del control de embarazo** (WEB-6.6, la pestaña *Reporte*): la hoja «CONTROL DE EMBARAZO»
     * del escritorio (`d_informe_pre_nata_peque.srd`), con la gesta, sus controles y su laboratorio.
     *
     * La ficha de la gesta sale del mapa del dato y va como datos del documento; los controles y el
     * laboratorio, una línea por fecha con los campos que tienen algo, también desde sus mapas
     * (`PreNatalDesarrolloFino::campos()` y `PrenaExamenB::campos()`): un campo nuevo de esas tablas aparece
     * en la hoja sin tocar esto.
     *
     * ⚠️ La **FPP de esta hoja suma 283** (`d_pre_natal_observa_reporte.srd:45`), no los 281 de la pestaña:
     * ver `InformePrenatal`.
     *
     * @param  array{gesta:Gesta, controles:\Illuminate\Support\Collection, laboratorios:\Illuminate\Support\Collection, hoy:\Carbon\CarbonInterface|null}  $partes
     */
    private function informeDelEmbarazo(Paciente $paciente, array $partes, string $fechaDeLaConsulta): DocumentoImpreso
    {
        $gesta = $partes['gesta'];
        $valores = $gesta->valores();
        $eg = $this->informesPrenatales->edadGestacional($gesta, $partes['hoy']);
        $fpp = $this->informesPrenatales->fpp($gesta->fur());

        $datos = [];
        foreach ([
            'FUR:' => $gesta->fur() === null ? null : FechaClinica::formato($gesta->fur()),
            'FPP:' => $fpp === null ? null : FechaClinica::formato($fpp),
            'Edad gestacional:' => $eg?->texto(),
            'Menarquia:' => $gesta->menarquia(),
            'Gestas:' => $valores['gestas'] ?? null,
            'Partos:' => $valores['partos'] ?? null,
            'Cesáreas:' => $valores['cesarea'] ?? null,
            'Abortos:' => $valores['abortos'] ?? null,
            'Otros:' => $valores['otros'] ?? null,
            'Tipiaje (madre):' => $gesta->tipiaje(),
            'Tipiaje (padre):' => $gesta->tipiajeDeLaPareja(),
            'Final de embarazo:' => ($valores['final'] ?? null) === 'S' ? 'Sí' : (($valores['final'] ?? null) === 'N' ? 'No' : null),
            'Embarazo múltiple:' => ($valores['multiple'] ?? null) === 'S' ? 'Sí' : (($valores['multiple'] ?? null) === 'N' ? 'No' : null),
            'Cantidad:' => $valores['cantidad'] ?? null,
        ] as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $datos[] = ['etiqueta' => $etiqueta, 'valor' => (string) $valor];
            }
        }

        $parrafos = [];
        $this->parrafosDeLasFechas($partes['controles'], PreNatalDesarrolloFino::campos(), 'Control del ', $parrafos);
        $this->parrafosDeLasFechas($partes['laboratorios'], PrenaExamenB::campos(), 'Laboratorio del ', $parrafos);
        $this->sumarParrafo($parrafos, 'Observaciones:', $gesta->observaciones());

        return new DocumentoImpreso(
            DocumentoImpreso::CONTROL_DE_EMBARAZO,
            DocumentoImpreso::CONTROL_DE_EMBARAZO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::CONTROL_DE_EMBARAZO][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::CONTROL_DE_EMBARAZO][1],
            $fechaDeLaConsulta,
            PacienteImpreso::datos($paciente, $fechaDeLaConsulta),
            $datos,
            $parrafos,
        );
    }

    /**
     * Una línea por fecha con los campos que tienen algo, con el rótulo del mapa de esa tabla.
     *
     * @param  \Illuminate\Support\Collection  $filas
     * @param  array<int,array<string,mixed>>  $campos
     * @param  array<int,array<string,mixed>>  $parrafos
     */
    private function parrafosDeLasFechas($filas, array $campos, string $rotulo, array &$parrafos): void
    {
        foreach ($filas as $fila) {
            $valores = [];

            foreach ($campos as $campo) {
                $valor = $fila->{$campo['columna']} ?? null;

                if ($valor !== null && (string) $valor !== '') {
                    // El rótulo se respeta tal cual (el escritorio abrevia con punto: «Hb.», «A.U.»): si ya
                    // termina en punto, el valor va después de un espacio, y si no, después de dos puntos.
                    $etiqueta = (string) $campo['etiqueta'];
                    $valores[] = (str_ends_with($etiqueta, '.') ? $etiqueta . ' ' : $etiqueta . ': ') . $valor;
                }
            }

            if ($valores !== []) {
                $this->sumarParrafo($parrafos, $rotulo . FechaClinica::formato((string) $fila->fecha) . ':', implode(' · ', $valores));
            }
        }
    }

    /**
     * **El informe del eco obstétrico del primer trimestre** (WEB-6.2): la hoja «ECOSONOGRAFÍA OBSTÉTRICA ·
     * PRIMER TRIMESTRE» del escritorio (`d_eco_obstetrico_print_nuevo_grande`).
     *
     * Se arma del **mapa del dato** (`EcoObstetrico::grupos()`): un párrafo por bloque, con los campos que
     * tienen algo, así que un campo nuevo del mapa sale en la hoja sin tocar esto. Los rótulos del
     * escritorio ya vienen sin dos puntos, así que se les pone el de la hoja.
     *
     * La **FPP** y la **EG** no son columnas del informe en el escritorio: se calculan de la FUR (la hoja del
     * escritorio las muestra en su encabezado con `relativedate(fur, 283)` y la fórmula de la EG de siempre).
     */
    private function ecoObstetrico(Paciente $paciente, EstudioEcografico $estudio, string $fecha): DocumentoImpreso
    {
        $parrafos = [];

        foreach (EcoObstetrico::grupos() as $grupo) {
            $valores = [];

            foreach ($grupo['campos'] as $campo) {
                $valor = $estudio->valor($campo['columna']);

                if ($valor !== null && $valor !== '') {
                    $valores[] = $campo['etiqueta'] . ': ' . $valor;
                }
            }

            if ($valores !== []) {
                $this->sumarParrafo($parrafos, $grupo['titulo'] . ':', implode(' · ', $valores));
            }
        }

        // La edad gestacional y la FPP del estudio, calculadas de su FUR (como el encabezado del escritorio).
        $eg = \App\Clinica\Embarazo\EdadGestacional::de($estudio->fur());

        if ($eg !== null) {
            $datos = [
                ['etiqueta' => 'EG:', 'valor' => $eg->texto()],
            ];

            $fpp = \App\Clinica\Embarazo\EdadGestacional::fpp($estudio->fur(), \App\Clinica\Embarazo\EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO);
            if ($fpp !== null) {
                $datos[] = ['etiqueta' => 'FPP:', 'valor' => FechaClinica::formato($fpp)];
            }
        } else {
            $datos = [];
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ECO_OBSTETRICO,
            DocumentoImpreso::ECO_OBSTETRICO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_OBSTETRICO][0] . ' · PRIMER TRIMESTRE',
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_OBSTETRICO][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $datos,
            $parrafos,
        );
    }

    /** ¿El eco obstétrico dice algo? La fecha, la FUR y la gesta no cuentan (son del embarazo, no hallazgos). */
    private function elEcoObstetricoEsDocumento(EstudioEcografico $estudio): bool
    {
        $noCuentan = ['fecha', 'fur', 'gesta'];
        $conDatos = 0;

        foreach (array_diff(EcoObstetrico::columnas(), $noCuentan) as $columna) {
            $valor = $estudio->valor($columna);

            if ($valor !== null && $valor !== '') {
                $conDatos++;
            }
        }

        return QueEsUnDocumento::ecografia($conDatos);
    }

    private function elEcoEsDocumento(EstudioEcografico $estudio): bool
    {
        $conDato = 0;
        foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
            if ($columna !== EcoGinecologico::COLUMNA_FECHA && $estudio->valor($columna) !== null) {
                $conDato++;
            }
        }

        return QueEsUnDocumento::ecografia($conDato, $estudio->valor(EcoGinecologico::COLUMNA_PURO_TEXTO) !== null);
    }

    /** El lugar a hospitalizar en una sola línea (`hospital` es un `char(60)`), o `null` si no se escribió. */
    private function lugar(object $orden): ?string
    {
        $lugar = TextoDeLaConsulta::limpio($orden->hospital);

        return $lugar === null ? null : (string) preg_replace('/\s+/u', ' ', $lugar);
    }

    // -------------------------------------------------------------------------------------------- Fechas

    /** El día de la consulta como `AAAA-MM-DD`. */
    private function fechaDeLaConsulta(Consulta $consulta): string
    {
        try {
            return Carbon::parse($consulta->fecha)->toDateString();
        } catch (Throwable $e) {
            return Carbon::now()->toDateString();
        }
    }

    /** El inicio del reposo; si la fila no tiene fecha (hay 8 así en el legado), el día de la consulta. */
    private function fechaDelReposo(object $reposo, string $fechaDeLaConsulta): string
    {
        return ReposoDeLaConsulta::fechaDeInicio($reposo->fdesde, $fechaDeLaConsulta);
    }
}
