<?php

namespace App\Clinica\Impresion;

use App\Actions\Consulta\AbrirConsulta;
use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\PacienteDeLaCita;
use App\Clinica\Consulta\TextosDelExamen;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Los documentos de una cita atendida** (WEB-5.2, ampliado en WEB-5.3a y WEB-5.4): de la cita de la agenda a la consulta que la
 * atendió y a lo que esa consulta tiene para imprimir —los récipes y las solicitudes (laboratorio, estudios, constancia, reposo, referencias,
 * hospitalización, informe y dieta)—. Es lo que la secretaría necesita para entregarle los papeles al paciente que acaba de salir del consultorio
 * **sin entrar a la consulta** (la consulta es sólo del médico).
 *
 * ## El vínculo
 *
 * `cola` no tiene `nroconsulta`: la cita y la consulta se relacionan por **`numhistoria` + fecha** (wiki
 * `04-historia-clinica.md`). La consulta de una cita es **la del día de la cita con el número más alto** —la misma que abre
 * "Editar consulta" (`AbrirConsulta::delDia`)—, así que la secretaría imprime los documentos de la consulta que el médico
 * está viendo. El paciente se resuelve con `PacienteDeLaCita`, igual que al atender.
 *
 * Tiene dos lecturas, con la misma regla:
 *
 *  - `deLaCita(cita)` — **una** cita: el paciente y la consulta, para abrir la lista o la vista previa.
 *  - `conDocumentos(citas)` — **varias** citas de la agenda, en lote (unas pocas consultas, no una por fila): cuáles tienen
 *    algo para imprimir, para ofrecer el botón sólo donde hay qué imprimir.
 *
 * ## Qué cuenta como «algo para imprimir» en el lote
 *
 * Un récipe con medicamentos (del paciente o de la pareja) o un **documento de verdad** de los de `QueEsUnDocumento`: una
 * constancia con algo propio del médico, un reposo real, una referencia, un informe real, una orden de hospitalización, una dieta con texto, una orden de
 * laboratorio o una solicitud de estudios (al menos un examen o estudio, o un resumen clínico con texto), un **eco** (el
 * pélvico de WEB-6.1 o el Doppler perinatal de WEB-6.3 con algún dato del estudio o su informe en texto libre) o un **resumen médico** (WEB-5.3c: algún bloque
 * con datos). Es **la misma regla** que
 * `SolicitudesParaImprimir::disponibles` aplica a una sola consulta, escrita acá como consulta de conjunto (SQL) porque es
 * por toda la agenda; los tests comparan las dos formas.
 *
 * Es de **sólo lectura**.
 */
final class DocumentosPorCita
{
    public function __construct(
        private PacienteDeLaCita $pacientes,
        private AbrirConsulta $consultas,
    ) {
    }

    /**
     * El paciente de una cita y la consulta del día en que se lo atendió: `pacienteId` y `nombre` (del paciente,
     * para el encabezado) y `consulta`, que es `null` si la cita no dice a quién atendió, el paciente todavía no
     * tiene historia o ese día no hubo consulta.
     *
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico del contexto
     * @return array{pacienteId:?int, nombre:string, consulta:?Consulta}
     */
    public function deLaCita(Cola $cita, array $registrosMedicos): array
    {
        $pacienteId = $this->pacientes->opcional($cita, $registrosMedicos);
        $paciente = $pacienteId === null ? null : Paciente::find($pacienteId);

        $nombre = $paciente === null
            ? 'el paciente de la cita'
            : trim(trim((string) $paciente->nombres) . ' ' . trim((string) $paciente->apellidos));

        $historia = $pacienteId === null ? null : $this->pacientes->historia($pacienteId, $registrosMedicos);
        $consulta = $historia === null ? null : $this->consultas->delDia($historia, Carbon::parse($cita->fecha));

        // La consulta tiene que ser del registro de la cita: la historia puede estar en otro registro del
        // mismo médico, y la agenda de acá es la de uno solo.
        if ($consulta !== null && $consulta->reg_medico !== $cita->reg_medico) {
            $consulta = null;
        }

        return ['pacienteId' => $pacienteId, 'nombre' => $nombre, 'consulta' => $consulta];
    }

    /**
     * Cuáles de las citas atendidas tienen algo para imprimir.
     *
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @return array<int,int>  los ids de `cola` con algo para imprimir (como claves y valores)
     */
    public function conDocumentos(string $regMedico, Collection $citas): array
    {
        $atendidas = $citas->filter(fn (CitaDeAgenda $cita) => $cita->atendida() && ! $cita->movida() && $cita->numHistoria !== null);

        if ($atendidas->isEmpty()) {
            return [];
        }

        // La consulta del día de cada cita (la de mayor número), por historia y fecha.
        $consultas = DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $atendidas->pluck('numHistoria')->unique()->all())
            ->whereIn(DB::raw('date(fecha)'), $atendidas->pluck('fecha')->unique()->all())
            ->get(['numhistoria', 'nroconsulta', 'fecha']);

        $delDia = [];
        foreach ($consultas as $consulta) {
            $clave = (int) $consulta->numhistoria . '|' . substr((string) $consulta->fecha, 0, 10);
            $delDia[$clave] = max($delDia[$clave] ?? 0, (int) $consulta->nroconsulta);
        }

        if ($delDia === []) {
            return [];
        }

        $historias = $atendidas->pluck('numHistoria')->unique()->all();
        $conAlgo = [];

        // Los récipes del paciente o el de la pareja: cualquier medicamento.
        foreach (['recipes', 'recipes_pareja'] as $tabla) {
            $this->marcar($conAlgo, DB::table($tabla)->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        }

        // Las solicitudes que son documentos de verdad (`QueEsUnDocumento`, en su forma de conjunto):
        //  - constancia con algo propio del médico (observación, diagnósticos editados o título);
        $this->marcar($conAlgo, DB::table('constancia_obs')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
            ->where(fn ($q) => $q
                ->whereRaw("TRIM(COALESCE(observacion01, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(observacion, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(titulo, '')) <> ''")), 'numhistoria', 'numconsulta');
        //  - reposo de más de un día o con justificación;
        $this->marcar($conAlgo, DB::table('reposo_paciente')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias)
            ->where(fn ($q) => $q->where('numdias', '>', 1)->orWhereRaw("TRIM(COALESCE(obser_reposo, '')) <> ''")), 'nrohistoria', 'nroconsulta');
        //  - referencia (cualquiera);
        $this->marcar($conAlgo, DB::table('referencia')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        //  - informe con texto que no sea la plantilla `.`;
        $this->marcar($conAlgo, DB::table('informe')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias)
            ->whereRaw("TRIM(COALESCE(descripcion, '')) NOT IN ('', '.')"), 'nrohistoria', 'nroconsulta');
        //  - hospitalización con el lugar, el diagnóstico o las recomendaciones (WEB-5.3b: la fila vacía que crea el escritorio no cuenta);
        $this->marcar($conAlgo, DB::table('hospitalizacion')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias)
            ->where(fn ($q) => $q
                ->whereRaw("TRIM(COALESCE(hospital, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(diagprev, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(dieta, '')) <> ''")), 'nrohistoria', 'nroconsulta');
        //  - dieta con texto (la tabla llama `histroria` a la historia y `consulta` al número de la consulta);
        $this->marcar($conAlgo, DB::table('dieta_paciente')->where('reg_medico', $regMedico)->whereIn('histroria', $historias)
            ->whereRaw("TRIM(COALESCE(descripcion, '')) <> ''"), 'histroria', 'consulta');
        //  - pre-factura (F9): al menos un concepto cargado;
        $this->marcar($conAlgo, DB::table('his_con_pre_factura')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias), 'numhistoria', 'nroconsulta');
        //  - orden de laboratorio (WEB-5.4): al menos un examen, o un resumen clínico con texto (`QueEsUnDocumento::laboratorio`; el literal
        //    `none` del DataWindow no es texto, como en `TextoDeLaConsulta`);
        $this->marcar($conAlgo, DB::table('examen_paciente')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        $this->marcar($conAlgo, DB::table('examen_obs')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
            ->whereRaw("TRIM(COALESCE(observacion, '')) <> ''")->whereRaw("LOWER(TRIM(observacion)) <> 'none'"), 'numhistoria', 'numconsulta');
        //  - orden de laboratorio de la pareja (WEB-11.21): algún examen o los datos de la pareja.
        $this->marcar($conAlgo, DB::table('examen_pareja')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        $this->marcar($conAlgo, DB::table('examen_pareja_datos')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        //  - solicitud de estudios (WEB-5.4): lo mismo con `radiologia_paciente` y `radiologia_obs`.
        $this->marcar($conAlgo, DB::table('radiologia_paciente')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        $this->marcar($conAlgo, DB::table('radiologia_obs')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
            ->whereRaw("TRIM(COALESCE(observacion, '')) <> ''")->whereRaw("LOWER(TRIM(observacion)) <> 'none'"), 'numhistoria', 'numconsulta');
        //  - los informes de las solapas de texto del examen (WEB-6.5: la colposcopía y la histeroscopía): el
        //    texto vive en la **consulta** (su columna de `consultas`, lo que imprime la hoja), no en una tabla
        //    aparte (`QueEsUnDocumento::texto`; el literal `none` del DataWindow tampoco es texto).
        foreach (TextosDelExamen::claves() as $solapa) {
            if (TextosDelExamen::documento($solapa) === null) {
                continue;
            }

            $columna = (string) TextosDelExamen::columna($solapa);

            $this->marcar($conAlgo, DB::table('consultas')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
                ->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")->whereRaw("LOWER(TRIM({$columna})) <> 'none'"), 'numhistoria', 'nroconsulta');
        }

        //  - **resumen médico** (WEB-5.3c): la hoja que se arma sola con los datos de toda la consulta. Es
        //    documento si **algún bloque tiene datos salvo el tratamiento** (la regla de
        //    `ResumenMedico::esDocumento`, escrita acá en conjunto): las tres columnas de `consultas`
        //    (enfermedad actual, resultado de exámenes y plan), los dos resúmenes del examen (`total_f` /
        //    `total_g`, con la clave `historia` + `consulta` de esa tabla), un motivo o un diagnóstico. El
        //    tratamiento —los medicamentos de un récipe— **no** cuenta (el récipe ya es su documento, y por
        //    eso no se mira `recipes`), como tampoco el `informe.para` ni la fecha: son de la cabecera.
        $this->marcar($conAlgo, DB::table('consultas')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
            ->where(function ($q) {
                foreach (['enfermedadactual', 'oido', 'laringe'] as $columna) {
                    $q->orWhere(function ($cada) use ($columna) {
                        $cada->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")
                            ->whereRaw("LOWER(TRIM(COALESCE({$columna}, ''))) <> 'none'");
                    });
                }
            }), 'numhistoria', 'nroconsulta');
        $this->marcar($conAlgo, DB::table('examen_fisico_nuevo')->where('reg_medico', $regMedico)->whereIn('historia', $historias)
            ->where(function ($q) {
                foreach (['total_f', 'total_g'] as $columna) {
                    $q->orWhere(function ($cada) use ($columna) {
                        $cada->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")
                            ->whereRaw("LOWER(TRIM(COALESCE({$columna}, ''))) <> 'none'");
                    });
                }
            }), 'historia', 'consulta');
        $this->marcar($conAlgo, DB::table('motivo_consulta_paciente')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');
        $this->marcar($conAlgo, DB::table('diagnostico_paciente')->where('reg_medico', $regMedico)->whereIn('nrohistoria', $historias), 'nrohistoria', 'nroconsulta');

        //  - informe del eco pélvico (WEB-6.1): algún campo del estudio cargado o el informe en texto libre
        //    del escritorio (`QueEsUnDocumento::ecografia`; la fecha sola no cuenta y el literal `none`
        //    tampoco, como en `EstudioEcografico::limpiar`). La lista de columnas sale del mapa del dato,
        //    así que un campo nuevo entra solo.
        $this->marcar($conAlgo, DB::table('eco_pelvico')->where('reg_medico', $regMedico)->whereIn('numhistoria', $historias)
            ->where(function ($q) {
                foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
                    if ($columna === EcoGinecologico::COLUMNA_FECHA) {
                        continue;
                    }

                    $q->orWhere(function ($cada) use ($columna) {
                        $cada->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")
                            ->whereRaw("LOWER(TRIM(COALESCE({$columna}, ''))) <> 'none'");
                    });
                }
            }), 'numhistoria', 'nroconsulta');

        //  - informe del eco Doppler perinatal (WEB-6.3): algún campo del estudio cargado o el informe en
        //    texto libre del escritorio (`puro_texto`, su modo «Plantillas»), con la misma vara
        //    (`QueEsUnDocumento::ecografia`). **La clave es `historia` + `consulta`**, no
        //    `numhistoria`/`nroconsulta` como el pélvico, y la ficha (fecha, FUR, gesta y `para`) no cuenta.
        $this->marcar($conAlgo, DB::table('eco_doppler')->where('reg_medico', $regMedico)->whereIn('historia', $historias)
            ->where(function ($q) {
                foreach (array_merge(
                    array_diff(EcoDoppler::columnas(), EcoDoppler::COLUMNAS_QUE_NO_CUENTAN),
                    [EcoDoppler::COLUMNA_PURO_TEXTO],
                ) as $columna) {
                    $q->orWhere(function ($cada) use ($columna) {
                        $cada->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")
                            ->whereRaw("LOWER(TRIM(COALESCE({$columna}, ''))) <> 'none'");
                    });
                }
            }), 'historia', 'consulta');

        //  - informe de los dos ecos obstétricos (WEB-6.2): algún campo del estudio cargado, sin contar su
        //    ficha (la fecha, la FUR y la gesta). Se suman acá para cerrar el hueco que dejó WEB-6.2: su hoja
        //    se imprimía desde la consulta, pero la secretaría no la veía en «Imprimir documentos» de la cita
        //    atendida. **Su clave también es `historia` + `consulta`**, y no `numhistoria`/`nroconsulta`.
        $this->marcarEstudio($conAlgo, DB::table('eco_obstetrico')->where('reg_medico', $regMedico)->whereIn('historia', $historias),
            EcoObstetrico::columnas(), EcoObstetrico::COLUMNAS_QUE_NO_CUENTAN, 'historia', 'consulta');
        $this->marcarEstudio($conAlgo, DB::table('eco_obstetrico_tercer')->where('reg_medico', $regMedico)->whereIn('historia', $historias),
            EcoObstetricoTercero::columnas(), EcoObstetricoTercero::COLUMNAS_QUE_NO_CUENTAN, 'historia', 'consulta');
        // El 2.º trimestre (WEB-11.14) vive en la tabla hermana.
        $this->marcarEstudio($conAlgo, DB::table('eco_obstetrico_tercer_2')->where('reg_medico', $regMedico)->whereIn('historia', $historias),
            EcoObstetricoTercero::columnas(), EcoObstetricoTercero::COLUMNAS_QUE_NO_CUENTAN, 'historia', 'consulta');

        //  - informe del ecocardiograma fetal (WEB-6.3): otro estudio del ítem 5, con la misma clave
        //    (`historia` + `consulta`) y su ficha más ancha (la fecha, la FUR, la gesta y la paridad). Su
        //    tabla trae el error de tipeo del legado (`ecocadiograma_fetal`, sin la «r»).
        $this->marcarEstudio($conAlgo, DB::table('ecocadiograma_fetal')->where('reg_medico', $regMedico)->whereIn('historia', $historias),
            EcoCardiogramaFetal::columnas(), EcoCardiogramaFetal::COLUMNAS_QUE_NO_CUENTAN, 'historia', 'consulta');

        //  - informe del eco mamario (WEB-6.4): otro estudio del ítem 5, con la misma clave (`historia` +
        //    `consulta`) y su ficha corta (sólo la fecha). Su tabla (`ultra_mama`) no se llama como su
        //    DataWindow: el vivo es `d_ultasonido_mamas`. Su `puro_texto` **no** cuenta: la hoja del mamario no
        //    tiene párrafos de texto libre (su modo «Plantillas» es otro DataWindow que la web no imprime).
        $this->marcarEstudio($conAlgo, DB::table('ultra_mama')->where('reg_medico', $regMedico)->whereIn('historia', $historias),
            EcoMamario::columnas(), EcoMamario::COLUMNAS_QUE_NO_CUENTAN, 'historia', 'consulta');

        //  - informe del eco abdominal (WEB-6.4), el séptimo y último tipo del ítem 5: su clave también es
        //    `historia` + `consulta` y su ficha es tan corta como la del mamario (sólo la fecha). Es una
        //    ecografía abdominal general (la misma tabla la usan cirugía y pediatría) y `prostata`/`vejiga_urina`
        //    no cuentan: no están en el mapa (son de urología y en ginecología van ocultas).
        $this->marcarEstudio($conAlgo, DB::table('ultra_abdominal')->where('reg_medico', $regMedico)->whereIn('historia', $historias),
            EcoAbdominal::columnas(), EcoAbdominal::COLUMNAS_QUE_NO_CUENTAN, 'historia', 'consulta');

        $ids = [];
        foreach ($atendidas as $cita) {
            $nroconsulta = $delDia[$cita->numHistoria . '|' . $cita->fecha] ?? null;

            if ($nroconsulta !== null && isset($conAlgo[$cita->numHistoria . '|' . $nroconsulta])) {
                $ids[$cita->id] = $cita->id;
            }
        }

        return $ids;
    }

    /**
     * Anota en `$conAlgo` las consultas (`historia|número`) de una tabla de estudio ecográfico que tienen
     * **algún campo del estudio** con dato: la ficha (las columnas de `$noCuentan`) no cuenta y el literal
     * `none` que dejó el escritorio tampoco, que es la misma vara de `EstudioEcografico::limpiar`.
     *
     * @param  array<string,bool>  $conAlgo
     * @param  array<int,string>  $columnas
     * @param  array<int,string>  $noCuentan
     */
    private function marcarEstudio(array &$conAlgo, $consulta, array $columnas, array $noCuentan, string $columnaHistoria, string $columnaConsulta): void
    {
        $this->marcar($conAlgo, $consulta->where(function ($q) use ($columnas, $noCuentan) {
            foreach (array_diff($columnas, $noCuentan) as $columna) {
                $q->orWhere(function ($cada) use ($columna) {
                    $cada->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")
                        ->whereRaw("LOWER(TRIM(COALESCE({$columna}, ''))) <> 'none'");
                });
            }
        }), $columnaHistoria, $columnaConsulta);
    }

    /**
     * Anota en `$conAlgo` las consultas (`historia|número`) que tienen al menos una fila de esa consulta.
     *
     * @param  array<string,bool>  $conAlgo
     */
    private function marcar(array &$conAlgo, $consulta, string $columnaHistoria, string $columnaConsulta): void
    {
        foreach ($consulta->distinct()->get([$columnaHistoria, $columnaConsulta]) as $fila) {
            $conAlgo[(int) $fila->{$columnaHistoria} . '|' . (int) $fila->{$columnaConsulta}] = true;
        }
    }
}
