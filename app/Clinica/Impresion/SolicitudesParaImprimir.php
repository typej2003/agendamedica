<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\TextoDelExamen;
use App\Clinica\Consulta\TextosDelExamen;
use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\DoctoresDelMedico;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\ReferenciasDeLaConsulta;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\ResumenMedico;
use App\Clinica\Consulta\ResumenMedicoDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Clinica\Embarazo\InformePrenatal;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PrenaExamenB;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\TagDeImagen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Consulta\EcoObstetricoSegundo;
use App\Clinica\Consulta\EcoObstetricoTercero;
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
 *  - **Resumen médico** (WEB-5.3c, `d_resumen_medico_gra`): la hoja que se **arma sola** con los datos de toda la consulta
 *    (motivo, enfermedad actual, examen físico, examen ginecológico, resultado de exámenes, diagnóstico, tratamiento y plan de
 *    tratamiento). No es la fila de ninguna tabla: la lee `ResumenMedicoDeLaConsulta` y la arma `ResumenMedico`. Su cabecera
 *    lleva el `informe.para` y su fecha, y sus bloques sin datos no se imprimen.
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
        private EcoObstetricoTercero $ecosObstetricosTerceros,
        private EcoObstetricoSegundo $ecosObstetricosSegundos,
        private EcoDoppler $dopplers,
        private EcoCardiogramaFetal $cardiogramasFetales,
        private EcoMamario $mamarios,
        private EcoAbdominal $abdominales,
        private DoctoresDelMedico $doctores,
        private InformePrenatal $informesPrenatales,
        private TextoDelExamen $textos,
        private ResumenMedicoDeLaConsulta $resumenes,
        private ImagenesParaElInforme $imagenesDelInforme,
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
        if ($this->elEcoObstetricoEsDocumento($estudioObstetrico, EcoObstetrico::columnas())) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_OBSTETRICO,
                'tipo' => DocumentoImpreso::ECO_OBSTETRICO,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_OBSTETRICO][1],
                // Sin los hallazgos ni la conclusión: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del eco obstétrico (1.er trimestre)',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // El del 2.º trimestre (WEB-11.14: tabla hermana) y el del 3.º: otro estudio cada uno, la misma regla para ofrecerlos.
        foreach ([
            [DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO, $this->ecosObstetricosSegundos, '2.º'],
            [DocumentoImpreso::ECO_OBSTETRICO_TERCERO, $this->ecosObstetricosTerceros, '3.er'],
        ] as [$claveDelTrimestre, $lecturaDelTrimestre, $nombreDelTrimestre]) {
            if ($this->elEcoObstetricoEsDocumento($lecturaDelTrimestre->de($consulta), EcoObstetricoTercero::columnas())) {
                $disponibles[] = [
                    'clave' => $claveDelTrimestre,
                    'tipo' => $claveDelTrimestre,
                    'titulo' => DocumentoImpreso::TITULOS[$claveDelTrimestre][1],
                    'resumen' => 'Informe del eco obstétrico (' . $nombreDelTrimestre . ' trimestre)',
                    'fecha' => $fechaDeLaConsulta,
                ];
            }
        }

        // El informe del eco Doppler perinatal (WEB-6.3): otro estudio más del ítem 5, con su propia ficha
        // (fecha, FUR, gesta y `para`), y la misma regla para ofrecerlo.
        $doppler = $this->dopplers->de($consulta);
        if ($this->elEcoDopplerEsDocumento($doppler)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_DOPPLER,
                'tipo' => DocumentoImpreso::ECO_DOPPLER,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_DOPPLER][1],
                // Sin los índices ni la conclusión: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del eco Doppler perinatal',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // El informe del ecocardiograma fetal (WEB-6.3): otro estudio del ítem 5, con su ficha obstetricia
        // (fecha, FUR, gesta y paridad) y la misma regla para ofrecerlo. Su clave también es `historia` +
        // `consulta` y su tabla trae el error de tipeo del legado (`ecocadiograma_fetal`, sin la «r»).
        $fetal = $this->cardiogramasFetales->de($consulta);
        if ($this->elEcoCardiogramaFetalEsDocumento($fetal)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
                'tipo' => DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_CARDIOGRAMA_FETAL][1],
                // Sin la biometría ni la conclusión: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del ecocardiograma fetal',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // El informe del eco mamario (WEB-6.4): otro estudio del ítem 5, con la misma clave (`historia` +
        // `consulta`) y la ficha más corta de todas (sólo la fecha). Su tabla (`ultra_mama`) no se llama como
        // su DataWindow (el vivo es `d_ultasonido_mamas`), y sus plantillas de texto son `formato_print` `UM`.
        $mamario = $this->mamarios->de($consulta);
        if ($this->elEcoMamarioEsDocumento($mamario)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_MAMARIO,
                'tipo' => DocumentoImpreso::ECO_MAMARIO,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_MAMARIO][1],
                // Sin los hallazgos ni la conclusión: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del eco mamario',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // El informe del eco abdominal (WEB-6.4), el séptimo y último tipo del ítem 5: su clave también es
        // `historia` + `consulta` y su ficha es tan corta como la del mamario (sólo la fecha). Es una
        // ecografía abdominal general (la misma tabla la usan cirugía y pediatría) y `prostata`/`vejiga_urina`
        // no cuentan: son de urología y en ginecología van ocultas.
        $abdominal = $this->abdominales->de($consulta);
        if ($this->elEcoAbdominalEsDocumento($abdominal)) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::ECO_ABDOMINAL,
                'tipo' => DocumentoImpreso::ECO_ABDOMINAL,
                'titulo' => DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_ABDOMINAL][1],
                // Sin los hallazgos ni las conclusiones: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe del eco abdominal',
                'fecha' => $fechaDeLaConsulta,
            ];
        }

        // Los informes de las solapas de texto del examen (WEB-6.5): la colposcopía y la histeroscopía. Cada
        // una se ofrece si tiene texto —el escritorio **no** crea la fila al abrir la consulta: es una columna
        // de `consultas`—, y las otras dos solapas de texto (citología y resultado de exámenes) no tienen hoja.
        foreach (TextosDelExamen::claves() as $solapa) {
            $documento = TextosDelExamen::documento($solapa);

            if ($documento === null || ! QueEsUnDocumento::texto($this->textos->de($consulta, $solapa))) {
                continue;
            }

            $disponibles[] = [
                'clave' => $documento,
                'tipo' => $documento,
                'titulo' => DocumentoImpreso::TITULOS[$documento][1],
                // Sin el texto: la lista de la cita la ve la secretaría.
                'resumen' => 'Informe de ' . mb_strtolower(DocumentoImpreso::TITULOS[$documento][1]),
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

        // El **resumen médico** (WEB-5.3c): la hoja que se arma sola con los datos de toda la consulta.
        // No es una fila de nadie, así que se ofrece si hay algo que resumir y, si no, no existe: ofrecerlo
        // vacío llenaría la lista de la secretaría con una hoja en blanco.
        $resumen = $this->resumenes->de($consulta);
        if ($resumen->esDocumento()) {
            $disponibles[] = [
                'clave' => DocumentoImpreso::RESUMEN_MEDICO,
                'tipo' => DocumentoImpreso::RESUMEN_MEDICO,
                'titulo' => 'Resumen médico',
                // Sin los datos clínicos: la lista de la cita la ve la secretaría.
                'resumen' => 'Resumen médico de la consulta',
                'fecha' => $resumen->fecha() ?? $fechaDeLaConsulta,
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

            return $this->elEcoEsDocumento($estudio)
                ? $this->conInformeDeEco($this->ecoPelvico($paciente, $estudio, $fechaDeLaConsulta), $consulta, $estudio, EcoGinecologico::grupos(), TiposDeEstudio::GINECOLOGICO, $estudio->valor(EcoGinecologico::COLUMNA_PURO_TEXTO))
                : null;
        }

        if ($clave === DocumentoImpreso::ECO_OBSTETRICO) {
            $estudio = $this->ecosObstetricos->de($consulta);

            return $this->elEcoObstetricoEsDocumento($estudio, EcoObstetrico::columnas())
                ? $this->conInformeDeEco($this->ecoObstetrico($paciente, $estudio, $fechaDeLaConsulta), $consulta, $estudio, EcoObstetrico::grupos(), TiposDeEstudio::OBSTETRICO)
                : null;
        }

        if ($clave === DocumentoImpreso::ECO_OBSTETRICO_TERCERO || $clave === DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO) {
            $estudio = ($clave === DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO ? $this->ecosObstetricosSegundos : $this->ecosObstetricosTerceros)->de($consulta);

            return $this->elEcoObstetricoEsDocumento($estudio, EcoObstetricoTercero::columnas())
                ? $this->conInformeDeEco(
                    $this->ecoObstetricoTercero($paciente, $estudio, $fechaDeLaConsulta, $clave),
                    $consulta, $estudio, EcoObstetricoTercero::grupos(),
                    $clave === DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO ? TiposDeEstudio::SEGUNDO : TiposDeEstudio::TERCERO,
                )
                : null;
        }

        if ($clave === DocumentoImpreso::ECO_DOPPLER) {
            $estudio = $this->dopplers->de($consulta);

            return $this->elEcoDopplerEsDocumento($estudio)
                ? $this->conInformeDeEco($this->ecoDoppler($paciente, $estudio, $fechaDeLaConsulta), $consulta, $estudio, EcoDoppler::grupos(), TiposDeEstudio::DOPPLER, $estudio->valor(EcoDoppler::COLUMNA_PURO_TEXTO))
                : null;
        }

        if ($clave === DocumentoImpreso::ECO_CARDIOGRAMA_FETAL) {
            $estudio = $this->cardiogramasFetales->de($consulta);

            return $this->elEcoCardiogramaFetalEsDocumento($estudio)
                ? $this->conInformeDeEco($this->ecoCardiogramaFetal($consulta, $paciente, $estudio, $fechaDeLaConsulta), $consulta, $estudio, EcoCardiogramaFetal::grupos(), TiposDeEstudio::FETAL, null, $this->motivoParaElEncabezado($consulta))
                : null;
        }

        if ($clave === DocumentoImpreso::ECO_MAMARIO) {
            $estudio = $this->mamarios->de($consulta);

            return $this->elEcoMamarioEsDocumento($estudio)
                ? $this->conInformeDeEco($this->ecoMamario($paciente, $estudio, $fechaDeLaConsulta, $registros), $consulta, $estudio, EcoMamario::grupos(), TiposDeEstudio::MAMARIO)
                : null;
        }

        if ($clave === DocumentoImpreso::ECO_ABDOMINAL) {
            $estudio = $this->abdominales->de($consulta);

            return $this->elEcoAbdominalEsDocumento($estudio)
                ? $this->conInformeDeEco($this->ecoAbdominal($paciente, $estudio, $fechaDeLaConsulta, $registros), $consulta, $estudio, EcoAbdominal::grupos(), TiposDeEstudio::ABDOMINAL)
                : null;
        }

        if (($solapa = TextosDelExamen::deLaHoja($clave)) !== null) {
            $texto = $this->textos->de($consulta, $solapa);

            return QueEsUnDocumento::texto($texto)
                ? $this->textoDelExamen($paciente, $clave, $texto, $fechaDeLaConsulta)
                : null;
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

        if ($clave === DocumentoImpreso::RESUMEN_MEDICO) {
            $resumen = $this->resumenes->de($consulta);

            return $resumen->esDocumento() ? $this->resumenMedico($paciente, $resumen, $fechaDeLaConsulta) : null;
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

    /**
     * **El resumen médico de la consulta** (WEB-5.3c): la hoja que el escritorio arma sola
     * (`d_resumen_medico_gra`) con los ocho bloques de `ResumenMedico`. El destinatario del informe
     * (`informe.para`) sale **en la cabecera, en una caja con borde**, y la fecha de la cabecera es
     * `informe.fe_cha` (o la de la consulta si no hay informe).
     *
     * El cuerpo lo arma el ViewModel de la hoja —con los rótulos literales y los bloques sin datos
     * omitidos—, así que acá no se escribe ninguna regla del dato.
     */
    private function resumenMedico(Paciente $paciente, ResumenMedico $resumen, string $fechaDeLaConsulta): DocumentoImpreso
    {
        $fecha = $resumen->fecha() ?? $fechaDeLaConsulta;

        return new DocumentoImpreso(
            DocumentoImpreso::RESUMEN_MEDICO,
            DocumentoImpreso::RESUMEN_MEDICO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::RESUMEN_MEDICO][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::RESUMEN_MEDICO][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            [],
            $resumen->parrafos(),
            $resumen->para(),
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
    /**
     * El motivo de consulta, como dato de la franja de arriba del ecocardiograma (el escritorio lo imprime primero, como el resumen médico).
     *
     * @return array<int,array{etiqueta:string, valor:string}>
     */
    private function motivoParaElEncabezado(Consulta $consulta): array
    {
        $motivos = $this->resumenes->motivosDeLaConsulta($consulta);

        return $motivos === [] ? [] : [['etiqueta' => 'MOTIVO DE CONSULTA:', 'valor' => implode(DocumentoImpreso::SEPARADOR_DE_ITEMS, $motivos)]];
    }

    /**
     * El informe de un eco **a hoja completa** (WEB-11.13): el formulario lleno con las opciones marcadas y, en otra hoja, las imágenes del
     * estudio. El documento en mitades que ya armó cada eco queda como base (sus datos cortos y su título).
     *
     * @param  array<int,array<string,mixed>>  $grupos  el mapa del dato del estudio
     * @param  string  $tipo  el tipo del estudio (`TiposDeEstudio`), que dice cuáles son sus imágenes
     * @param  string|null  $textoLibre  el `puro_texto` del escritorio, si el estudio lo trae (eco pélvico y Doppler)
     * @param  array<int,array{etiqueta:string, valor:string}>  $encabezadoExtra  datos de la franja de arriba que no son del mapa (el motivo de consulta del ecocardiograma)
     */
    private function conInformeDeEco(DocumentoImpreso $base, Consulta $consulta, EstudioEcografico $estudio, array $grupos, string $tipo, ?string $textoLibre = null, array $encabezadoExtra = []): DocumentoImpreso
    {
        $informe = ArmadorDeInformeDeEco::armar($estudio, $grupos, array_merge($base->fijos, $encabezadoExtra), $textoLibre)
            ->conLasImagenes($this->imagenesDelInforme->de($consulta, TagDeImagen::deUnEco($tipo)));

        return $base->conInforme($informe);
    }

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
        return $this->hojaDelEcoObstetrico($paciente, $estudio, $fecha, EcoObstetrico::grupos(), DocumentoImpreso::ECO_OBSTETRICO);
    }

    /** La del 2.º/3.er trimestre: la misma hoja con su mapa y su título. */
    private function ecoObstetricoTercero(Paciente $paciente, EstudioEcografico $estudio, string $fecha, string $clave = DocumentoImpreso::ECO_OBSTETRICO_TERCERO): DocumentoImpreso
    {
        return $this->hojaDelEcoObstetrico($paciente, $estudio, $fecha, EcoObstetricoTercero::grupos(), $clave);
    }

    /**
     * La hoja de un eco obstétrico: un párrafo por bloque del mapa y la EG/FPP de su FUR. El título con su
     * trimestre sale de `DocumentoImpreso::tituloConSufijo()`, que también usa el formato en blanco.
     */
    private function hojaDelEcoObstetrico(Paciente $paciente, EstudioEcografico $estudio, string $fecha, array $grupos, string $clave): DocumentoImpreso
    {
        $parrafos = [];

        foreach ($grupos as $grupo) {
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
            $clave,
            $clave,
            DocumentoImpreso::tituloConSufijo($clave),
            DocumentoImpreso::TITULOS[$clave][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $datos,
            $parrafos,
        );
    }

    /**
     * **El informe de una solapa de texto del examen** (WEB-6.5): la hoja «INFORME DE COLPOSCOPÍA»
     * (`d_colposcopia_1` / `d_colposcopia_2`) y la «INFORME DE HISTEROSCOPÍA» (`d_histeroscopia`), cuyo
     * **cuerpo es el texto de su columna de `consultas`** (`observaciones` y `cuello`; ver la cita en
     * `TextosDelExamen` y, para la histeroscopía, `d_histeroscopia.srd:29`).
     *
     * Es el texto tal como lo escribió el médico, un párrafo por renglón: el escritorio imprime un solo
     * campo de texto con el informe, sin rótulos armados. Las **fotos** que el escritorio le anexa (su
     * subreporte, `d_imagenes_consul_pac_*`) llegan con la parte de imágenes por examen de WEB-6.5.
     */
    private function textoDelExamen(Paciente $paciente, string $documento, string $texto, string $fecha): DocumentoImpreso
    {
        return new DocumentoImpreso(
            $documento,
            $documento,
            DocumentoImpreso::tituloConSufijo($documento),
            DocumentoImpreso::TITULOS[$documento][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            [],
            DocumentoImpreso::parrafosDe($texto),
        );
    }

    /**
     * **El informe del eco Doppler perinatal** (WEB-6.3): la hoja «ECO DOPPLER PERINATAL» del escritorio
     * (`d_eco_doppler_print`), en el orden de su fuente: el **texto** libre del estudio (el memo grande, que
     * no lleva rótulo), la **grilla de cuatro vasos** con su título, los **ductos venosos**, la **señal
     * venoso umbilical**, la **relación aorta-cava**, las **sugerencias** y la **conclusión** (en singular,
     * como el DataWindow de impresión, `:91-92`).
     *
     * La ficha del encabezado —la gesta, la FUR, la EG y el destinatario— va como **datos cortos**, como en
     * el DataWindow: la EG se calcula de la FUR con la misma cuenta de siempre (`EdadGestacional::de`; el
     * `compute` del DataWindow es idéntico al de los ecos obstétricos).
     *
     * Los rótulos de los desplegables y de la grilla salen del **mapa del dato** (`EcoDoppler`), el mismo
     * que pinta el formulario: un campo nuevo del mapa aparece en la pantalla y en la hoja sin tocar esto.
     */
    private function ecoDoppler(Paciente $paciente, EstudioEcografico $estudio, string $fecha): DocumentoImpreso
    {
        $datos = [];

        foreach ([
            'Gesta:' => $estudio->valor('gesta'),
            'FUR:' => $estudio->fur() === null ? null : FechaClinica::formato($estudio->fur()),
            'EG:' => \App\Clinica\Embarazo\EdadGestacional::de($estudio->fur())?->texto(),
            'PARA:' => $estudio->valor('para'),
        ] as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $datos[] = ['etiqueta' => $etiqueta, 'valor' => (string) $valor];
            }
        }

        $parrafos = [];

        // El memo grande del estudio: el DataWindow no le pone rótulo.
        foreach (DocumentoImpreso::parrafosDe($estudio->valor('texto')) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        // La grilla de cuatro vasos: una línea por arteria, con sus índices rotulados. El bloque sin ninguna
        // medida no se imprime.
        $filasDeLaGrilla = [];

        foreach (EcoDoppler::grilla() as $fila) {
            $valores = [];

            foreach ($fila['campos'] as $campo) {
                $valor = $estudio->valor($campo['columna']);

                if ($valor !== null && $valor !== '') {
                    $valores[] = $campo['eje'] . ': ' . $valor;
                }
            }

            if ($valores !== []) {
                $filasDeLaGrilla[] = ['etiqueta' => $fila['etiqueta'] . ':', 'texto' => implode(' · ', $valores)];
            }
        }

        if ($filasDeLaGrilla !== []) {
            // El título del bloque, con el rótulo que le puso el doctor al DataWindow de impresión
            // (`d_eco_doppler_print.srd:63`), como un párrafo de su propia línea.
            $parrafos[] = ['texto' => '', 'etiqueta' => 'Eco Doppler Obstétrico en 4 Vasos', 'aparte' => true];

            foreach ($filasDeLaGrilla as $filaDeLaGrilla) {
                $this->sumarParrafo($parrafos, $filaDeLaGrilla['etiqueta'], $filaDeLaGrilla['texto']);
            }
        }

        // Los tres desplegables, con los rótulos de la hoja (la señal venoso umbilical va sin la
        // «pulsatilidad» que sí dice la pantalla, como en el DataWindow de impresión).
        foreach ([
            'ductos_venosos' => 'Ductos Venosos:',
            'ven_umbilical' => 'Señal Venoso Umbilical:',
            'relac_aorta' => 'Relación Aorta-Cava:',
        ] as $columna => $etiqueta) {
            $this->sumarParrafo($parrafos, $etiqueta, EcoDoppler::textoDe($columna, $estudio->valor($columna)));
        }

        // Las dos áreas del final: la pantalla las rotula «Observaciones:» y «Conclusiones:»; la hoja,
        // «Sugerencias:» y «Conclusión:» (singular), como el DataWindow de impresión.
        foreach (['sugerencias' => 'Sugerencias:', 'conclusion' => 'Conclusión:'] as $columna => $etiqueta) {
            foreach (DocumentoImpreso::parrafosDe($estudio->valor($columna), $etiqueta, true) as $parrafo) {
                $parrafos[] = $parrafo;
            }
        }

        // El modo «Plantillas» del escritorio: si dejó un informe escrito en texto libre, se imprime tal cual
        // (la web lo muestra y no lo edita), como en el eco pélvico.
        foreach (DocumentoImpreso::parrafosDe($estudio->valor(EcoDoppler::COLUMNA_PURO_TEXTO), 'Informe en texto libre:', true) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ECO_DOPPLER,
            DocumentoImpreso::ECO_DOPPLER,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_DOPPLER][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_DOPPLER][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $datos,
            $parrafos,
        );
    }

    /**
     * **El informe del ecocardiograma fetal** (WEB-6.3): la hoja «ECOCARDIOGRAMA FETAL» del escritorio
     * (`d_eco_ecocardiografia_fetal_print`), en el orden de su detalle: el **MOTIVO DE CONSULTA** (el mismo
     * dato y el mismo orden que el resumen médico), la **paridad** del encabezado (PARA / ABORTOS / CESARÉAS /
     * OTROS), el **texto del estudio** (el memo grande, que no lleva rótulo), la **biometría** (cada medida en
     * milímetros y la «MEDIDA» con su «(Percentil)»), los **datos anatómicos**, los **datos placento -
     * funiculares** y la **conclusión** (los signos y las sugerencias).
     *
     * Su cabecera lleva la GESTA, la FUR, la FPP y la EG como **datos cortos**, como en el DataWindow: la FPP
     * es `fur + 283` —el número de los ecos y los reportes— y la EG es la cuenta de siempre, las dos de
     * `EdadGestacional` (la regla está escrita una sola vez).
     *
     * ⚠️ **El «REFERIDO POR:» del escritorio imprime `pacientes.ocupacion`, no la columna `referido` del
     * estudio** (`d_eco_ecocardiografia_fetal_print.srd:57-58`): es una rareza del legado y se replica tal
     * cual. La columna `referido` existe, va oculta en el DataWindow y en ginecología no se usa.
     *
     * Los rótulos y las unidades de los bloques salen del **mapa del dato** (`EcoCardiogramaFetal::grupos()`),
     * el mismo que pinta el formulario: un campo nuevo del mapa aparece en la pantalla y en la hoja sin tocar
     * esto.
     */
    private function ecoCardiogramaFetal(Consulta $consulta, Paciente $paciente, EstudioEcografico $estudio, string $fecha): DocumentoImpreso
    {
        $fur = $estudio->fur();
        $eg = \App\Clinica\Embarazo\EdadGestacional::de($fur);
        $fpp = \App\Clinica\Embarazo\EdadGestacional::fpp($fur, \App\Clinica\Embarazo\EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO);

        $datos = [];

        foreach ([
            'GESTA:' => EcoCardiogramaFetal::textoDe('gesta', $estudio->valor('gesta')),
            'FUR:' => $fur === null ? null : FechaClinica::formato($fur),
            'FPP:' => $fpp === null ? null : FechaClinica::formato($fpp),
            'EG:' => $eg?->texto(),
            // La rareza del legado: el «REFERIDO POR:» del informe es la ocupación del paciente.
            'REFERIDO POR:' => TextoDeLaConsulta::limpio($paciente->ocupacion ?? null),
        ] as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $datos[] = ['etiqueta' => $etiqueta, 'valor' => (string) $valor];
            }
        }

        $parrafos = [];

        // El motivo de consulta, como lo imprime el resumen médico (el mismo dato y el mismo orden).
        $motivos = $this->resumenes->motivosDeLaConsulta($consulta);
        if ($motivos !== []) {
            $parrafos[] = DocumentoImpreso::parrafoDeLista('MOTIVO DE CONSULTA:', $motivos);
        }

        // La paridad: el escritorio la imprime en su propia franja, sin rótulo de grupo.
        $paridad = [];
        foreach (['PARA:' => 'parto', 'ABORTOS:' => 'aborto', 'CESARÉAS:' => 'cesarea', 'OTROS:' => 'otros'] as $etiqueta => $columna) {
            $valor = $estudio->valor($columna);

            if ($valor !== null) {
                $paridad[] = $etiqueta . ' ' . $valor;
            }
        }

        if ($paridad !== []) {
            $parrafos[] = ['texto' => implode(' · ', $paridad), 'etiqueta' => null, 'aparte' => false];
        }

        // El memo grande del estudio: el DataWindow no le pone rótulo.
        foreach (DocumentoImpreso::parrafosDe($estudio->valor(EcoCardiogramaFetal::COLUMNA_TEXTO_INICIO)) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        // La biometría, cada medida con su unidad (los `compute` del informe) y la «MEDIDA» con su percentil.
        $this->sumarParrafo($parrafos, 'DATOS BIOMETRICOS:', $this->linea(
            $this->medidasDelEstudio($estudio, EcoCardiogramaFetal::camposDelBloque('Datos biométricos')),
        ));

        // Los datos anatómicos: es un memo largo, así que va con su rótulo en su propia línea.
        foreach (DocumentoImpreso::parrafosDe($estudio->valor(EcoCardiogramaFetal::COLUMNA_ANATOMIA), 'DATOS ANATOMICOS:', true) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        // Los datos placento-funiculares: sus desplegables traducidos y sus dos medidas con su sufijo.
        $this->sumarParrafo($parrafos, 'DATOS PLACENTO - FUNICULARES:', $this->linea(
            $this->medidasDelEstudio($estudio, EcoCardiogramaFetal::camposDelBloque('Datos placento - funiculares')),
        ));

        // La conclusión: el rótulo del bloque y, debajo, los signos y las sugerencias con los suyos.
        $conclusion = $estudio->valor('conclusion');
        $sugerencia = $estudio->valor('sugerencia');

        if ($conclusion !== null || $sugerencia !== null) {
            $parrafos[] = ['texto' => '', 'etiqueta' => 'CONCLUSIÓN:', 'aparte' => true];
        }

        foreach (DocumentoImpreso::parrafosDe($conclusion, 'SIGNOS ECOGRÁFICOS SUGESTIVOS DE:', true) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        foreach (DocumentoImpreso::parrafosDe($sugerencia, 'SUGERENCIAS:', true) as $parrafo) {
            $parrafos[] = $parrafo;
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
            DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_CARDIOGRAMA_FETAL][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_CARDIOGRAMA_FETAL][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $datos,
            $parrafos,
        );
    }

    /**
     * **El informe del eco mamario** (WEB-6.4): la hoja «ULTRASONIDO DE MAMAS» del escritorio
     * (`d_ultasonido_mamas_print`), con los diez datos de su detalle **en el orden de la fuente**: el
     * **referido por** (la cédula del catálogo `doctores` resuelta a su nombre, como el `dddw` del
     * DataWindow), el **transductor**, la **menarquia**, los **quirúrgicos**, las **hormonas**, los hallazgos
     * de cada **mama** y cada **axila** y la **conclusión**.
     *
     * Los cinco primeros son datos cortos (van juntos, sin partirse); los cinco textos largos van con su
     * rótulo en su propia línea, como se escriben. Un campo sin dato no se imprime.
     *
     * ⚠️ **`para` no se imprime**: va oculto en el DataWindow y en el dump está siempre vacío; la web no lo
     * ofrece ni lo lee. Y **`puro_texto` tampoco**: el modo «Plantillas» del escritorio es **otro** DataWindow
     * de impresión (`d_ultasonido_mamas_print_puro`), que la web no imprime —lo muestra en la pantalla y nada
     * más—.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico, para resolver el referido
     */
    private function ecoMamario(Paciente $paciente, EstudioEcografico $estudio, string $fecha, array $registros): DocumentoImpreso
    {
        $datos = [];

        // Los cinco datos cortos del encabezado, en el orden del DataWindow de impresión. El referido guarda
        // la cédula del colega y la hoja imprime su nombre (el `displaycolumn` de su `dddw`); si el catálogo ya
        // no lo tiene, se muestra la cédula en vez de inventar un nombre.
        $referido = $estudio->valor(EcoMamario::COLUMNA_REFERIDO);
        $medico = $referido === null ? null : $this->doctores->porCedula($registros, (int) $referido);

        foreach ([
            'Referido por' => $referido === null
                ? null
                : (string) ($medico->nombre ?? ('Médico ' . $referido)),
            'Transductor' => $estudio->valor('tranductor'),
            'Menarquia' => $estudio->valor('menarquia'),
            'Quirúrgicos' => $estudio->valor('quirurgicos'),
            'Hormonas' => $estudio->valor('hormonas'),
        ] as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $datos[] = ['etiqueta' => $etiqueta . ':', 'valor' => (string) $valor];
            }
        }

        // Los cuatro hallazgos y la conclusión: textos largos, con el rótulo en su propia línea.
        $parrafos = [];

        foreach ([
            'mama_derecha' => 'Mama derecha:',
            'axila_derecha' => 'Axila derecha:',
            'mama_izquierda' => 'Mama izquierda:',
            'axila_izquierda' => 'Axila izquierda:',
            'conclusion' => 'Conclusión:',
        ] as $columna => $etiqueta) {
            foreach (DocumentoImpreso::parrafosDe($estudio->valor($columna), $etiqueta, true) as $parrafo) {
                $parrafos[] = $parrafo;
            }
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ECO_MAMARIO,
            DocumentoImpreso::ECO_MAMARIO,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_MAMARIO][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_MAMARIO][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $datos,
            $parrafos,
        );
    }

    /**
     * **El informe del eco abdominal** (WEB-6.4): la hoja «ULTRASONIDO ABDOMINAL» del escritorio
     * (`d_ultasonido_abdominal_print`, su título en `:37`), con los datos de su detalle **en el orden de la
     * fuente**: el **transductor** y el **referido por** (los dos del encabezado), los hallazgos de cada
     * **órgano** (hígado, vesícula biliar, porta y colédoco, páncreas y los dos riñones), los **cuatro
     * desplegables** (el volumen residual, la ascitis, el retroperitoneo y los vasos), las **observaciones**
     * (la columna `otros`, que la hoja rotula así) y las **conclusiones**.
     *
     * Los dos primeros son datos cortos (van juntos, sin partirse) y el resto va con su rótulo, como se
     * escriben. Un dato sin valor no se imprime.
     *
     * El referido guarda la **cédula** del colega y la hoja imprime su nombre (el `displaycolumn` de su
     * `dddw`); si el catálogo ya no lo tiene, se muestra la cédula en vez de inventar un nombre.
     *
     * ⚠️ **`puro_texto` no se imprime**: el modo «Plantillas» del escritorio es **otro** DataWindow de
     * impresión (`d_ultasonido_abdominal_print_puro`), que la web no imprime —lo muestra en la pantalla y
     * nada más—. Y **`prostata` y `vejiga_urina` tampoco**: son de urología y en ginecología van ocultas en
     * el formulario y en la hoja del escritorio (`visible="0"`).
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico, para resolver el referido
     */
    private function ecoAbdominal(Paciente $paciente, EstudioEcografico $estudio, string $fecha, array $registros): DocumentoImpreso
    {
        $datos = [];

        // Los dos datos cortos del encabezado, en el orden del DataWindow de impresión (el transductor y el
        // referido por van en la misma franja; primero el transductor, como su x).
        $referido = $estudio->valor(EcoAbdominal::COLUMNA_REFERIDO);
        $medico = $referido === null ? null : $this->doctores->porCedula($registros, (int) $referido);

        foreach ([
            'Transductor' => $estudio->valor('transductor'),
            'Referido por' => $referido === null
                ? null
                : (string) ($medico->nombre ?? ('Médico ' . $referido)),
        ] as $etiqueta => $valor) {
            if ($valor !== null && $valor !== '') {
                $datos[] = ['etiqueta' => $etiqueta . ':', 'valor' => (string) $valor];
            }
        }

        $parrafos = [];

        // Los seis órganos, cada uno con su rótulo en su propia línea (son textos largos).
        foreach ([
            'higado' => 'Hígado:',
            'vesicula_bilial' => 'Vesícula biliar:',
            'porta_coledoco' => 'Porta y colédoco:',
            'pancreas' => 'Páncreas:',
            'rinon_derecho' => 'Rinón derecho:',
            'rinon_izquierdo' => 'Rinón izquierdo:',
        ] as $columna => $etiqueta) {
            foreach (DocumentoImpreso::parrafosDe($estudio->valor($columna), $etiqueta, true) as $parrafo) {
                $parrafos[] = $parrafo;
            }
        }

        // Los cuatro desplegables, en el orden del DataWindow, con su código traducido (Si→Sí, No→No,
        // Normal/Anormal tal cual). Son cortos: van con el rótulo en la misma línea.
        foreach ([
            'vol_res_v_p_m' => 'Volumen residual vesical Post-Micción:',
            'ascitis' => 'Ascitis:',
            'retroperitoneo' => 'Retroperitoneo:',
            'vasos_sanguineos' => 'Vasos sanguíneos:',
        ] as $columna => $etiqueta) {
            $this->sumarParrafo($parrafos, $etiqueta, EcoAbdominal::textoDe($columna, $estudio->valor($columna)));
        }

        // Las dos áreas del final: la pantalla rotula `otros` como «Otros» y la hoja como «Observaciones»,
        // como pide su DataWindow de impresión.
        foreach (['otros' => 'Observaciones:', 'conclusiones' => 'Conclusiones:'] as $columna => $etiqueta) {
            foreach (DocumentoImpreso::parrafosDe($estudio->valor($columna), $etiqueta, true) as $parrafo) {
                $parrafos[] = $parrafo;
            }
        }

        return new DocumentoImpreso(
            DocumentoImpreso::ECO_ABDOMINAL,
            DocumentoImpreso::ECO_ABDOMINAL,
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_ABDOMINAL][0],
            DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_ABDOMINAL][1],
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $datos,
            $parrafos,
        );
    }

    /**
     * El texto de cada campo de un bloque del ecocardiograma fetal que tenga dato: "DBP: 25 mm", "PLACENTA:
     * Anterior", "GRADO: II / III". El rótulo y —en los desplegables— la traducción del código salen del mapa;
     * la unidad y el sufijo, del campo.
     *
     * @param  array<int,array<string,mixed>>  $campos
     * @return array<int,string>
     */
    private function medidasDelEstudio(EstudioEcografico $estudio, array $campos): array
    {
        $valores = [];

        foreach ($campos as $campo) {
            $valor = $estudio->valor($campo['columna']);

            if ($valor === null) {
                continue;
            }

            $texto = ($campo['tipo'] ?? 'texto') === 'opciones'
                ? (EcoCardiogramaFetal::textoDe($campo['columna'], $valor) ?? $valor)
                : $valor;

            foreach ([$campo['unidad'] ?? null, $campo['sufijo'] ?? null] as $agregado) {
                if ($agregado !== null && $agregado !== '') {
                    $texto .= ' ' . $agregado;
                }
            }

            $valores[] = $campo['etiqueta'] . ': ' . $texto;
        }

        return $valores;
    }

    /** ¿El eco obstétrico dice algo? La fecha, la FUR y la gesta no cuentan (son del embarazo, no hallazgos). */
    private function elEcoObstetricoEsDocumento(EstudioEcografico $estudio, array $columnas): bool
    {
        return QueEsUnDocumento::ecografia($this->camposDelEstudioConDato($estudio, $columnas, ['fecha', 'fur', 'gesta']));
    }

    /**
     * ¿El ecocardiograma fetal dice algo? Su ficha es la más ancha de los ecos —la fecha, la FUR, la gesta y la
     * paridad—, así que no cuentan siete columnas. Los dos memos (`texto_inicio` y `anatomi_datos`) **sí**: son
     * el cuerpo del informe y el escritorio los precarga.
     */
    private function elEcoCardiogramaFetalEsDocumento(EstudioEcografico $estudio): bool
    {
        return QueEsUnDocumento::ecografia($this->camposDelEstudioConDato(
            $estudio,
            EcoCardiogramaFetal::columnas(),
            EcoCardiogramaFetal::COLUMNAS_QUE_NO_CUENTAN,
        ));
    }

    /**
     * ¿El eco mamario dice algo? Su ficha es la más corta de los ecos —**sólo la fecha**—, así que no cuenta
     * una columna. A diferencia del eco pélvico y del Doppler, **no** cuenta el informe en texto libre del
     * escritorio (`puro_texto`): la hoja del mamario no tiene párrafos de texto libre —su modo «Plantillas» es
     * otro DataWindow de impresión (`d_ultasonido_mamas_print_puro`), que la web no imprime—, así que una fila
     * que sólo trae ese texto no ofrece un informe vacío.
     */
    private function elEcoMamarioEsDocumento(EstudioEcografico $estudio): bool
    {
        return QueEsUnDocumento::ecografia(
            $this->camposDelEstudioConDato($estudio, EcoMamario::columnas(), EcoMamario::COLUMNAS_QUE_NO_CUENTAN),
        );
    }

    /**
     * ¿El eco abdominal dice algo? Su ficha es tan corta como la del mamario —**sólo la fecha**—, así que no
     * cuenta una columna. Tampoco cuenta el informe en texto libre del escritorio (`puro_texto`): la hoja del
     * abdominal no tiene párrafos de texto libre —su modo «Plantillas» es otro DataWindow de impresión
     * (`d_ultasonido_abdominal_print_puro`), que la web no imprime—, así que una fila que sólo trae ese texto
     * no ofrece un informe vacío. `prostata` y `vejiga_urina` no están en el mapa (son de urología): no
     * cuentan ni se imprimen.
     */
    private function elEcoAbdominalEsDocumento(EstudioEcografico $estudio): bool
    {
        return QueEsUnDocumento::ecografia(
            $this->camposDelEstudioConDato($estudio, EcoAbdominal::columnas(), EcoAbdominal::COLUMNAS_QUE_NO_CUENTAN),
        );
    }

    /**
     * ¿El eco Doppler dice algo? Su ficha tiene una columna más —el `para`— y, como el eco pélvico, cuenta
     * el **informe en texto libre** del escritorio (`puro_texto`): es lo que se imprime.
     */
    private function elEcoDopplerEsDocumento(EstudioEcografico $estudio): bool
    {
        return QueEsUnDocumento::ecografia(
            $this->camposDelEstudioConDato($estudio, EcoDoppler::columnas(), EcoDoppler::COLUMNAS_QUE_NO_CUENTAN),
            $estudio->valor(EcoDoppler::COLUMNA_PURO_TEXTO) !== null,
        );
    }

    /**
     * Cuántas columnas de un estudio traen algo, sin contar las de su ficha. Es la misma vara que la pantalla
     * y la Action (`QueEsUnDocumento::ecografia`).
     *
     * @param  array<int,string>  $columnas
     * @param  array<int,string>  $noCuentan
     */
    private function camposDelEstudioConDato(EstudioEcografico $estudio, array $columnas, array $noCuentan): int
    {
        $conDatos = 0;

        foreach (array_diff($columnas, $noCuentan) as $columna) {
            $valor = $estudio->valor($columna);

            if ($valor !== null && $valor !== '') {
                $conDatos++;
            }
        }

        return $conDatos;
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
