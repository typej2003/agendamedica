<?php

namespace App\Clinica\Listados;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El listado de consultas por rango** (WEB-6.8): lo que el escritorio muestra en
 * `w_reporte_consulta_barbaro.srw` (*Indicadores de Consultas*) con sus DataWindows `d_reporte_barbaro_*`.
 *
 * Las reglas del dato, y las decisiones del usuario (2026-10-14) que lo apartan del legado, están en
 * `FiltroDeConsultas`. Acá vive la consulta:
 *
 *  - **Una fila por consulta** (`consultas`), con su paciente (`medico_pacientes` + `pacientes`) y la fecha de la
 *    consulta. Los motivos, los diagnósticos y los medicamentos se leen **aparte, por lote**, y se pegan a su
 *    consulta: así una consulta con tres diagnósticos y dos medicamentos sale **una** vez y no seis, que es lo que
 *    hacía el producto cartesiano del legado.
 *  - **Los filtros de contenido son `EXISTS`**: una consulta entra si tiene un motivo / un diagnóstico / un
 *    medicamento cuyo **nombre** contiene lo escrito (sin acentos ni mayúsculas, todas las palabras), y si su
 *    paciente tiene un antecedente que lo contiene. El nombre es el del catálogo del médico y, si el catálogo no lo
 *    tiene, el que quedó copiado en la fila (el escritorio lo copia al agregar).
 *  - **Todo se acota al `reg_medico` del contexto**: en la consulta, en el pivote del paciente, en los
 *    subconsultas y en las lecturas por lote. El número de historia se repite entre consultorios (cada
 *    instalación numera desde 1), así que atarlo al registro es lo que evita cruzar a dos pacientes homónimos.
 *
 * **El orden** es el más reciente primero (fecha de la consulta descendente), con el número de historia y el de
 * consulta como desempate estables. El legado no ordenaba.
 *
 * Sólo lectura.
 */
final class ConsultasPorRango
{
    /** Cuántas historias se piden de una vez en las lecturas por lote (un `IN` enorme falla en algunos motores). */
    private const LOTE = 500;

    /** Cuántas filas muestra una página de la pantalla. La hoja impresa las trae todas. */
    public const POR_PAGINA = 25;

    /**
     * Una página de las consultas que cumplen los filtros, del médico del contexto.
     *
     * El corte lo hace la base: con el rango abierto el listado abarca años de consultas, y traerlas enteras
     * para mostrar veinticinco era lo que hacía lenta la pantalla.
     *
     * @return LengthAwarePaginator<int,object> filas con `consulta_id`, `numhistoria`, `nroconsulta`, `fecha`,
     *         `paciente_id`, `nombres`, `apellidos`, `fnacimiento`, y las listas `motivos`, `diagnosticos`,
     *         `medicamentos` y `antecedentes` (arreglos de texto; los antecedentes sólo si se filtró por uno)
     */
    public function filas(string $regMedico, FiltroDeConsultas $filtro): LengthAwarePaginator
    {
        $pagina = $this->consulta($regMedico, $filtro)
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        // Las listas se leen sólo para las consultas de **esta** página, no para todo el listado.
        $this->conSusListas($pagina->getCollection(), $regMedico, $filtro);

        return $pagina;
    }

    /**
     * Todas las filas, sin cortar: es lo que imprime la hoja del listado (el papel no tiene páginas).
     *
     * @return Collection<int,object>
     */
    public function todas(string $regMedico, FiltroDeConsultas $filtro): Collection
    {
        return $this->conSusListas(
            $this->consulta($regMedico, $filtro)->get(),
            $regMedico,
            $filtro,
        );
    }

    /**
     * La consulta base, con sus filtros y el orden del legado.
     *
     * **Sin `distinct()`**: `medicos.reg_medico` es único, el pivote tiene `UNIQUE (medico_id, paciente_id)` y
     * cada consulta es una fila, así que un ID de consulta aparece una sola vez (los `whereExists` no
     * duplican). Quitarlo permite paginar en la base.
     */
    private function consulta(string $regMedico, FiltroDeConsultas $filtro)
    {
        $fechas = $filtro->rangoDeConsulta();
        $nacimiento = $filtro->rangoDeNacimiento();

        $consulta = DB::table('consultas')
            ->join('medico_pacientes', function ($join) {
                // En MySQL `consultas` (tabla del legado, `utf8mb4_general_ci`) y el pivote
                // `medico_pacientes` (`utf8mb4_unicode_ci`) tienen collation distinta y el `=` falla con el
                // error 1267 «Illegal mix of collations»: se fuerza la del pivote, igual que en
                // `EmbarazosEntreFechas`. SQLite no lo necesita y no entiende ese `COLLATE`.
                if (DB::connection()->getDriverName() === 'mysql') {
                    $join->whereRaw('medico_pacientes.numhistoria = consultas.numhistoria COLLATE utf8mb4_unicode_ci')
                        ->whereRaw('medico_pacientes.reg_medico = consultas.reg_medico COLLATE utf8mb4_unicode_ci');
                } else {
                    $join->on('medico_pacientes.numhistoria', '=', 'consultas.numhistoria')
                        ->on('medico_pacientes.reg_medico', '=', 'consultas.reg_medico');
                }
            })
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('consultas.reg_medico', $regMedico)
            ->whereBetween('consultas.fecha', [$fechas->desde(), $fechas->hasta()])
            ->whereBetween('pacientes.fnacimiento', [$nacimiento->desde(), $nacimiento->hasta()]);

        $this->conMotivo($consulta, $regMedico, $filtro->motivo());
        $this->conDiagnostico($consulta, $regMedico, $filtro->diagnostico());
        $this->conMedicamento($consulta, $regMedico, $filtro->medicamento());
        $this->conAntecedente($consulta, $regMedico, $filtro->antecedente());

        return $consulta
            ->orderByDesc('consultas.fecha')
            ->orderBy('consultas.numhistoria')
            ->orderBy('consultas.nroconsulta')
            ->select([
                'consultas.id as consulta_id',
                'consultas.numhistoria',
                'consultas.nroconsulta',
                'consultas.fecha',
                'pacientes.id as paciente_id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.fnacimiento',
            ]);
    }

    // ------------------------------------------------------------------------------- Los filtros

    private function conMotivo($consulta, string $regMedico, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }

        $consulta->whereExists(function ($sub) use ($regMedico, $texto) {
            $sub->select(DB::raw(1))->from('motivo_consulta_paciente as mp')
                ->leftJoin('motivos_consulta as mc', function ($join) {
                    $join->on('mc.codemotivo', '=', 'mp.codemotivo')->on('mc.reg_medico', '=', 'mp.reg_medico');
                })
                ->where('mp.reg_medico', $regMedico)
                ->whereColumn('mp.nrohistoria', 'consultas.numhistoria')
                ->whereColumn('mp.nroconsulta', 'consultas.nroconsulta');
            $this->contiene($sub, ["COALESCE(mc.descripcion, mp.descripcion, '')"], $texto);
        });
    }

    private function conDiagnostico($consulta, string $regMedico, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }

        $consulta->whereExists(function ($sub) use ($regMedico, $texto) {
            $sub->select(DB::raw(1))->from('diagnostico_paciente as dp')
                ->leftJoin('diagnosticos as dg', function ($join) {
                    $join->on('dg.codediagnostico', '=', 'dp.codediagnostico')->on('dg.reg_medico', '=', 'dp.reg_medico');
                })
                ->where('dp.reg_medico', $regMedico)
                ->whereColumn('dp.nrohistoria', 'consultas.numhistoria')
                ->whereColumn('dp.nroconsulta', 'consultas.nroconsulta');
            $this->contiene($sub, ["COALESCE(dg.descripcion, dp.detalle_diagnostco, '')"], $texto);
        });
    }

    /** El nombre del medicamento es el que quedó copiado en el récipe o el del vademécum del médico. */
    private function conMedicamento($consulta, string $regMedico, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }

        $consulta->whereExists(function ($sub) use ($regMedico, $texto) {
            $sub->select(DB::raw(1))->from('recipes as rc')
                ->leftJoin('vademecum as vd', function ($join) {
                    $join->on('vd.codemedicina', '=', 'rc.codemedicina')->on('vd.reg_medico', '=', 'rc.reg_medico');
                })
                ->where('rc.reg_medico', $regMedico)
                ->whereColumn('rc.nrohistoria', 'consultas.numhistoria')
                ->whereColumn('rc.nroconsulta', 'consultas.nroconsulta');
            $this->contiene($sub, ["COALESCE(rc.descripcion, '')", "COALESCE(vd.nombrecomercial, '')", "COALESCE(vd.nombregenerico, '')"], $texto);
        });
    }

    /** El antecedente es **del paciente** (no de la consulta): se busca por su número de historia. */
    private function conAntecedente($consulta, string $regMedico, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }

        $consulta->whereExists(function ($sub) use ($regMedico, $texto) {
            $sub->select(DB::raw(1))->from('antece_paciente as ap')
                ->leftJoin('antecedentes as an', function ($join) {
                    $join->on('an.codantecedente', '=', 'ap.codeantecedente')->on('an.reg_medico', '=', 'ap.reg_medico');
                })
                ->where('ap.reg_medico', $regMedico)
                ->whereColumn('ap.numhistoria', 'consultas.numhistoria');
            $this->contiene($sub, ["COALESCE(an.descripcion, ap.descripcion, '')"], $texto);
        });
    }

    /**
     * Exige que **cada palabra** del texto esté en **alguna** de las expresiones, sin acentos ni mayúsculas y
     * tomando los comodines de `LIKE` como texto (el mismo criterio que la búsqueda de pacientes). Se compara
     * columna por columna y no con una concatenación: `||` es la concatenación en SQLite pero un `OR` en MySQL.
     *
     * @param  array<int,string>  $expresiones
     */
    private function contiene($consulta, array $expresiones, string $texto): void
    {
        foreach (BusquedaDePacientes::palabras($texto) as $palabra) {
            $termino = '%' . BusquedaDePacientes::escaparLike(BusquedaDePacientes::normalizar($palabra)) . '%';

            $consulta->where(function ($grupo) use ($expresiones, $termino) {
                foreach ($expresiones as $expresion) {
                    $grupo->orWhereRaw(BusquedaDePacientes::columnaNormalizada($expresion) . " like ? escape '!'", [$termino]);
                }
            });
        }
    }

    // ------------------------------------------------------------------------- Las listas por consulta

    /**
     * Pega a cada consulta sus motivos, sus diagnósticos y sus medicamentos (y los antecedentes que coinciden, si se
     * filtró por uno). Se lee por lote de historias y se agrupa por `numhistoria` + `nroconsulta`.
     */
    private function conSusListas(Collection $filas, string $regMedico, FiltroDeConsultas $filtro): Collection
    {
        if ($filas->isEmpty()) {
            return $filas;
        }

        $historias = $filas->pluck('numhistoria')->unique()->values();

        $motivos = $this->porConsulta($historias, fn (array $lote) => DB::table('motivo_consulta_paciente as mp')
            ->leftJoin('motivos_consulta as mc', function ($join) {
                $join->on('mc.codemotivo', '=', 'mp.codemotivo')->on('mc.reg_medico', '=', 'mp.reg_medico');
            })
            ->where('mp.reg_medico', $regMedico)->whereIn('mp.nrohistoria', $lote)
            ->orderBy('mp.id')
            ->get(['mp.nrohistoria as historia', 'mp.nroconsulta as consulta', DB::raw('COALESCE(mc.descripcion, mp.descripcion) as nombre')]));

        $diagnosticos = $this->porConsulta($historias, fn (array $lote) => DB::table('diagnostico_paciente as dp')
            ->leftJoin('diagnosticos as dg', function ($join) {
                $join->on('dg.codediagnostico', '=', 'dp.codediagnostico')->on('dg.reg_medico', '=', 'dp.reg_medico');
            })
            ->where('dp.reg_medico', $regMedico)->whereIn('dp.nrohistoria', $lote)
            ->orderBy('dp.orden')->orderBy('dp.id')
            ->get(['dp.nrohistoria as historia', 'dp.nroconsulta as consulta', DB::raw('COALESCE(dg.descripcion, dp.detalle_diagnostco, dp.codediagnostico) as nombre')]));

        $medicamentos = $this->porConsulta($historias, fn (array $lote) => DB::table('recipes as rc')
            ->leftJoin('vademecum as vd', function ($join) {
                $join->on('vd.codemedicina', '=', 'rc.codemedicina')->on('vd.reg_medico', '=', 'rc.reg_medico');
            })
            ->where('rc.reg_medico', $regMedico)->whereIn('rc.nrohistoria', $lote)
            ->orderBy('rc.recipe')->orderBy('rc.orden')->orderBy('rc.id')
            ->get(['rc.nrohistoria as historia', 'rc.nroconsulta as consulta',
                DB::raw('COALESCE(NULLIF(TRIM(rc.descripcion), \'\'), vd.nombrecomercial, vd.nombregenerico, rc.codemedicina) as nombre')]));

        // Los antecedentes son del paciente: se muestran sólo los que coinciden con lo filtrado.
        $antecedentes = [];

        if ($filtro->antecedente() !== null) {
            foreach ($historias->chunk(self::LOTE) as $lote) {
                $consulta = DB::table('antece_paciente as ap')
                    ->leftJoin('antecedentes as an', function ($join) {
                        $join->on('an.codantecedente', '=', 'ap.codeantecedente')->on('an.reg_medico', '=', 'ap.reg_medico');
                    })
                    ->where('ap.reg_medico', $regMedico)->whereIn('ap.numhistoria', $lote->all());
                $this->contiene($consulta, ["COALESCE(an.descripcion, ap.descripcion, '')"], $filtro->antecedente());

                foreach ($consulta->orderBy('ap.id')->get(['ap.numhistoria as historia', DB::raw('COALESCE(an.descripcion, ap.descripcion) as nombre')]) as $fila) {
                    $antecedentes[(int) $fila->historia][] = $this->nombre($fila->nombre);
                }
            }
        }

        return $filas->map(function (object $fila) use ($motivos, $diagnosticos, $medicamentos, $antecedentes) {
            $clave = (int) $fila->numhistoria . '|' . (int) $fila->nroconsulta;

            $fila->motivos = $motivos[$clave] ?? [];
            $fila->diagnosticos = $diagnosticos[$clave] ?? [];
            $fila->medicamentos = $medicamentos[$clave] ?? [];
            $fila->antecedentes = array_values(array_unique($antecedentes[(int) $fila->numhistoria] ?? []));

            return $fila;
        });
    }

    /**
     * Lee por lote de historias y agrupa los nombres por `historia|consulta`, sin vacíos ni repetidos.
     *
     * @param  Collection<int,mixed>  $historias
     * @param  callable(array):Collection  $leer  recibe un lote de historias y devuelve filas con
     *                                                 `historia`, `consulta` y `nombre`
     * @return array<string,array<int,string>>
     */
    private function porConsulta(Collection $historias, callable $leer): array
    {
        $agrupado = [];

        foreach ($historias->chunk(self::LOTE) as $lote) {
            foreach ($leer($lote->values()->all()) as $fila) {
                $nombre = $this->nombre($fila->nombre);

                if ($nombre !== '') {
                    $agrupado[(int) $fila->historia . '|' . (int) $fila->consulta][] = $nombre;
                }
            }
        }

        return array_map(fn (array $nombres) => array_values(array_unique($nombres)), $agrupado);
    }

    /** Un nombre en una sola línea y sin espacios de más; `''` si no dice nada. */
    private function nombre($valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (CamposDePaciente::texto($valor) ?? '')));
    }
}
