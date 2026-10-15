<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use Illuminate\Support\Facades\DB;

/**
 * **La lectura del resumen médico de una consulta** (WEB-5.3c): los ocho datos que el escritorio imprime
 * en `d_resumen_medico_gra`, leídos de las tablas donde viven, más el destinatario y la fecha de su
 * cabecera (`informe.para` y `informe.fe_cha`).
 *
 * Es **la** definición del dato (una sola para la pantalla, la impresión y la historia del paciente), y la
 * única que consulta la base: el armado de los textos con sus rótulos es `ResumenMedico`, que se prueba
 * sin base de datos.
 *
 * La fila de cada dato se busca por la clave del escritorio —`numhistoria`/`nrohistoria` +
 * `nroconsulta`, y `historia` + `consulta` en `examen_fisico_nuevo`, que es la que usa la evaluación
 * física (ver `ExamenFisicoDeLaConsulta::filaDe`)— acotada al `reg_medico` de la consulta.
 *
 * Es de **sólo lectura**: no crea ni modifica ninguna fila.
 */
final class ResumenMedicoDeLaConsulta
{
    /** Las columnas de `consultas` que el resumen imprime (enfermedad actual, resultado de exámenes y plan). */
    private const COLUMNAS_DE_LA_CONSULTA = ['enfermedadactual', 'oido', 'laringe'];

    public function __construct(private RecipesDeLaConsulta $recipes)
    {
    }

    /** El resumen médico de la consulta, ya leído. */
    public function de(Consulta $consulta): ResumenMedico
    {
        $regMedico = (string) $consulta->reg_medico;
        $historia = (int) $consulta->numhistoria;
        $nroconsulta = (int) $consulta->nroconsulta;

        // Las tres columnas de `consultas` que el resumen imprime. Se leen de la base —y no del modelo que
        // llegó— para que la lectura sea la misma que la de conjunto de `conResumen` (la que usa la historia
        // del paciente): una consulta que se acaba de escribir no puede salir con los valores viejos.
        $deLaConsulta = DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $historia)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('id')
            ->first(self::COLUMNAS_DE_LA_CONSULTA);

        // La cabecera del resumen es la del informe de esa consulta (`informe.para` en la caja y
        // `informe.fe_cha` como fecha): si no hay informe, los dos quedan vacíos y la hoja usa la fecha de
        // la consulta.
        $informe = DB::table('informe')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $historia)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('id')
            ->first(['para', 'fe_cha']);

        $examen = DB::table('examen_fisico_nuevo')
            ->where('reg_medico', $regMedico)
            ->where('historia', $historia)
            ->where('consulta', $nroconsulta)
            ->orderBy('id')
            ->first(['total_f', 'total_g']);

        return new ResumenMedico(
            [
                ResumenMedico::MOTIVO_DE_CONSULTA => $this->motivos($regMedico, $historia, $nroconsulta),
                ResumenMedico::ENFERMEDAD_ACTUAL => $deLaConsulta->enfermedadactual ?? null,
                ResumenMedico::EXAMEN_FISICO => $examen->total_f ?? null,
                ResumenMedico::EXAMEN_GINECOLOGICO => $examen->total_g ?? null,
                ResumenMedico::RESULTADO_DE_EXAMENES => $deLaConsulta->oido ?? null,
                ResumenMedico::DIAGNOSTICO => $this->diagnosticos($regMedico, $historia, $nroconsulta),
                ResumenMedico::TRATAMIENTO => $this->tratamiento($regMedico, $historia, $nroconsulta),
                ResumenMedico::PLAN_DE_TRATAMIENTO => $deLaConsulta->laringe ?? null,
            ],
            TextoDeLaConsulta::limpio($informe->para ?? null),
            $this->fecha($informe->fe_cha ?? null),
        );
    }

    /**
     * **Los motivos de una consulta**, ya resueltos y ordenados, fuera del resumen médico. Es lo que reusa la
     * hoja del ecocardiograma fetal (WEB-6.3), que también abre con «MOTIVO DE CONSULTA:»: la lectura es **la
     * misma** que la del bloque del resumen (una sola definición del dato, R3 de `PLAN-WEB.md`).
     *
     * @return array<int,string>
     */
    public function motivosDeLaConsulta(Consulta $consulta): array
    {
        return $this->motivos((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta);
    }

    /**
     * **Qué consultas de una historia tienen resumen médico**, por su `nroconsulta`.
     *
     * Es la misma regla que `ResumenMedico::esDocumento()` —algún bloque con datos **salvo el
     * tratamiento**— pero escrita en conjunto, para la historia del paciente: ahí hay que decidir el
     * documento de cada consulta y no se puede leer el resumen de todas una por una. Por eso acá **no**
     * entra `recipes` (el récipe es su propio documento, no el resumen).
     *
     * @return array<int,int>
     */
    public function conResumen(string $regMedico, int $numero): array
    {
        $numeros = [];

        // La enfermedad actual, el resultado de exámenes o el plan (las tres columnas de `consultas`).
        $this->sumar($numeros, DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->where(fn ($q) => $this->conTexto($q, self::COLUMNAS_DE_LA_CONSULTA))
            ->distinct()
            ->pluck('nroconsulta'));

        // El examen físico y el ginecológico (`total_f` / `total_g`).
        $this->sumar($numeros, DB::table('examen_fisico_nuevo')
            ->where('reg_medico', $regMedico)
            ->where('historia', $numero)
            ->where(fn ($q) => $this->conTexto($q, ['total_f', 'total_g']))
            ->distinct()
            ->pluck('consulta'));

        // Los motivos y los diagnósticos: cualquier fila cuenta (el escritorio las crea con el dato).
        $this->sumar($numeros, DB::table('motivo_consulta_paciente')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->distinct()
            ->pluck('nroconsulta'));

        $this->sumar($numeros, DB::table('diagnostico_paciente')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->distinct()
            ->pluck('nroconsulta'));

        $numeros = array_map('intval', array_keys($numeros));
        sort($numeros);

        return $numeros;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * Los motivos de la consulta: la descripción del **catálogo** (`motivos_consulta.descripcion`, que es
     * la que imprime el subreporte) y, si el catálogo no está, la que quedó guardada en la fila del
     * paciente (el escritorio la copia al agregar). Ordenados por descripción, como el `ORDER BY` del
     * DataWindow.
     *
     * @return array<int,string>
     */
    private function motivos(string $regMedico, int $historia, int $nroconsulta): array
    {
        return DB::table('motivo_consulta_paciente as p')
            ->leftJoin('motivos_consulta as m', function ($join) {
                $join->on('m.codemotivo', '=', 'p.codemotivo')
                    ->on('m.reg_medico', '=', 'p.reg_medico');
            })
            ->where('p.reg_medico', $regMedico)
            ->where('p.nrohistoria', $historia)
            ->where('p.nroconsulta', $nroconsulta)
            ->orderByRaw('COALESCE(m.descripcion, p.descripcion) ASC')
            ->orderBy('p.id')
            ->selectRaw('COALESCE(m.descripcion, p.descripcion) AS descripcion')
            ->pluck('descripcion')
            ->all();
    }

    /**
     * Los diagnósticos de la consulta en el orden del escritorio (`orden`), con la **descripción del
     * catálogo** (`diagnosticos.descripcion`, que es la que el `dddw` del DataWindow muestra en lugar del
     * código). Si el catálogo no está, la descripción que quedó guardada en la fila y, por último, el
     * código: nunca se inventa un nombre.
     *
     * @return array<int,string>
     */
    private function diagnosticos(string $regMedico, int $historia, int $nroconsulta): array
    {
        return DB::table('diagnostico_paciente as d')
            ->leftJoin('diagnosticos as c', function ($join) {
                $join->on('c.codediagnostico', '=', 'd.codediagnostico')
                    ->on('c.reg_medico', '=', 'd.reg_medico');
            })
            ->where('d.reg_medico', $regMedico)
            ->where('d.nrohistoria', $historia)
            ->where('d.nroconsulta', $nroconsulta)
            ->orderBy('d.orden')
            ->orderBy('d.id')
            ->selectRaw('COALESCE(c.descripcion, d.detalle_diagnostco, d.codediagnostico) AS descripcion')
            ->pluck('descripcion')
            ->all();
    }

    /**
     * El tratamiento: **todos** los medicamentos de los récipes de la consulta (`recipes`), con el nombre
     * que el escritorio imprime —`nombrecomercial + '/' + presentacion`, el `compute` del subreporte— **y sus
     * indicaciones** («KEFORAL/Cap. 500 mg — una cada 8 horas por 7 días»).
     *
     * Las indicaciones de cada medicamento se **calculan** acá y no se guardan en ningún lado (decisión del usuario,
     * 2026-10-15), así que corregir un récipe corrige el resumen. El texto libre del ítem TRATAMIENTO del escritorio
     * (`consultas.laringe`) es otro bloque, `PLAN_DE_TRATAMIENTO`, que la web escribe desde WEB-5.7.
     *
     * Se resuelve con la lectura de los récipes (`RecipesDeLaConsulta::deLaConsulta`), que es la que ya
     * conoce el vademécum, el respaldo de la fila y el código: el resumen no vuelve a escribir esa regla.
     *
     * @return array<int,string>
     */
    private function tratamiento(string $regMedico, int $historia, int $nroconsulta): array
    {
        return $this->recipes->deLaConsulta($regMedico, $historia, $nroconsulta)
            ->flatMap(fn (object $recipe) => $recipe->items)
            ->map(function (object $item) {
                $nombre = trim((string) $item->nombre);
                // Las indicaciones en una sola línea: el renglón de la lista no se parte entre dos medicamentos.
                $indicaciones = trim((string) preg_replace('/\s+/u', ' ', (string) ($item->indicaciones ?? '')));

                if ($nombre === '') {
                    return '';
                }

                return $indicaciones === '' ? $nombre : $nombre . ' — ' . $indicaciones;
            })
            ->filter(fn (string $linea) => $linea !== '')
            ->values()
            ->all();
    }

    /** La fecha de la cabecera como `AAAA-MM-DD`, o `null`. */
    private function fecha($valor): ?string
    {
        $fecha = TextoDeLaConsulta::limpio($valor);

        return $fecha === null ? null : substr($fecha, 0, 10);
    }

    /**
     * Suma los números de consulta que trae una lectura al índice: la clave es el número, así que una
     * consulta entra una sola vez sin importar cuántas fuentes la nombren.
     *
     * @param  array<int,bool>  $numeros
     * @param  \Illuminate\Support\Collection<int,mixed>  $valores
     */
    private function sumar(array &$numeros, $valores): void
    {
        foreach ($valores as $valor) {
            $numero = TextoDeLaConsulta::limpio($valor);

            if ($numero !== null) {
                $numeros[(int) $numero] = true;
            }
        }
    }

    /**
     * Las condiciones de «esa columna tiene texto»: no vale el vacío ni el literal `none` que deja el
     * DataWindow (la misma vara que `TextoDeLaConsulta`, escrita en SQL).
     *
     * @param  array<int,string>  $columnas
     */
    private function conTexto($q, array $columnas): void
    {
        foreach ($columnas as $columna) {
            $q->orWhere(function ($cada) use ($columna) {
                $cada->whereRaw("TRIM(COALESCE({$columna}, '')) <> ''")
                    ->whereRaw("LOWER(TRIM(COALESCE({$columna}, ''))) <> 'none'");
            });
        }
    }
}
