<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\ReferenciasDeLaConsulta;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\Paciente;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Throwable;

/**
 * **Las solicitudes de una consulta, listas para imprimir** (WEB-5.3a, WEB-5.3b): qué hay para imprimir (constancia, reposo,
 * referencias, hospitalización, informe y dieta) y cada una armada como `DocumentoImpreso`.
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
    public function documento(Consulta $consulta, int $pacienteId, array $registros, string $clave): ?DocumentoImpreso
    {
        $paciente = Paciente::find($pacienteId);

        if ($paciente === null) {
            return null;
        }

        $fechaDeLaConsulta = $this->fechaDeLaConsulta($consulta);

        if ($clave === DocumentoImpreso::CONSTANCIA) {
            return $this->constancia($consulta, $paciente, $fechaDeLaConsulta);
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
            'CONSTANCIA',
            'Constancia',
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
            'REPOSO MÉDICO',
            'Reposo médico',
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
            'REFERENCIA',
            'Referencia',
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
            'INFORME MÉDICO',
            'Informe médico',
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
            'ORDEN DE HOSPITALIZACIÓN',
            'Orden de hospitalización',
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $lugar === null ? [] : [['etiqueta' => 'HOSPITALIZAR EN:', 'valor' => $lugar]],
            array_merge(
                DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($orden->diagprev), 'DIAGNÓSTICO PROVISIONAL:', true),
                DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($orden->dieta), 'RECOMENDACIONES:', true),
            ),
        );
    }

    private function dieta(Paciente $paciente, object $dieta, string $fecha): DocumentoImpreso
    {
        $titulo = TextoDeLaConsulta::limpio($dieta->dieta);

        return new DocumentoImpreso(
            DocumentoImpreso::DIETA,
            DocumentoImpreso::DIETA,
            'DIETA / RECOMENDACIONES',
            'Dieta / Recomendaciones',
            $fecha,
            PacienteImpreso::datos($paciente, $fecha),
            $titulo === null ? [] : [['etiqueta' => 'DIETA:', 'valor' => (string) preg_replace('/\s+/u', ' ', $titulo)]],
            DocumentoImpreso::parrafosDe(TextoDeLaConsulta::limpio($dieta->descripcion)),
        );
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
