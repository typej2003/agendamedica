<?php

namespace App\Sync\Escritorio;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\MedicoPaciente;
use App\Models\SyncCarga;
use App\Models\SyncChange;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aplica en el API los cambios que registró el escritorio PowerBuilder (Fase 2, subida).
 * Diseño en bridge/DISENO-FASE-2.md.
 *
 * Cada cambio trae la tabla, la operación, la clave primaria de la fila EN EL ESCRITORIO, la fila
 * (si no es un borrado) y la fecha UTC en que se hizo. Reglas:
 *
 *  - **Gana la última edición, por columna**, igual que entre las apps: para citas y pacientes (lo que
 *    el app edita) se conservan las columnas que el app cambió DESPUÉS del cambio del escritorio, según
 *    `sync_changes` (source `mobile`). Las ediciones del escritorio también se anotan ahí (source
 *    `escritorio`), así la regla del app las respeta. No se usa `updated_at`: lo mueven también la
 *    carga inicial y las propias escrituras del escritorio, y haría perder ediciones del escritorio.
 *    En el resto de las tablas el app solo crea filas: el cambio del escritorio se aplica siempre.
 *  - **Números de historia y consulta:** el escritorio manda SUS números. Historias, consultas y citas
 *    guardan esa clave en `clave_escritorio`; con ella se traducen los números de todas las tablas.
 *    Si el escritorio crea una historia o consulta cuyo número ya usó el app para otra cosa, el API le
 *    da el siguiente libre y anota la equivalencia.
 *  - Cada cambio se aplica en su propia transacción. Uno rechazado (p. ej. una consulta de una
 *    historia que todavía no subió) no frena a los demás: el escritorio lo reintenta en la próxima vuelta.
 */
class CambiosEscritorio
{
    /** Operaciones que manda el escritorio. `T` = "la tabla entera" (tablas sin clave primaria). */
    private const OPERACIONES = ['I', 'U', 'D', 'T'];

    /** @var array<string, ?string> historia del escritorio -> numhistoria del API */
    private array $historias = [];

    /** @var array<string, ?array> "h|c" del escritorio -> [numhistoria, nroconsulta] del API */
    private array $consultas = [];

    public function __construct(private TablasLegado $tablas)
    {
    }

    /**
     * @param  array<int, array>  $cambios  [{id, tabla, op, clave: {..}, fila: {..}, filas: [..], fecha}]
     * @return array respuesta para el escritorio (plana: la lee PowerBuilder como clave=valor)
     */
    public function subir(SyncCarga $carga, array $cambios): array
    {
        $confirmados = [];
        $rechazados = [];
        $omitidos = 0;
        $parciales = 0;

        foreach ($this->enOrden($cambios) as $cambio) {
            $id = $cambio['id'] ?? null;
            try {
                $resultado = DB::transaction(fn () => $this->aplicar($carga, $cambio));
                if ($resultado === 'omitido') {
                    $omitidos++;
                } elseif ($resultado === 'parcial') {
                    $parciales++;
                }
                $confirmados[] = $id;
            } catch (RechazoCambio $e) {
                $rechazados[] = $id . ':' . $e->getMessage();
            } catch (Throwable $e) {
                Log::error('Sync escritorio: error aplicando un cambio', [
                    'reg_medico' => $carga->reg_medico, 'cambio' => $cambio, 'error' => $e->getMessage(),
                ]);
                $rechazados[] = $id . ':' . mb_substr($e->getMessage(), 0, 200);
            }
            // Lo traducido puede haber cambiado dentro de la transacción (historia o consulta nueva).
            $this->historias = [];
            $this->consultas = [];
        }

        return [
            'ok'          => true,
            'recibidos'   => count($cambios),
            'confirmados' => implode(',', array_filter($confirmados, fn ($v) => $v !== null)),
            'omitidos'    => $omitidos,
            'parciales'   => $parciales,
            'rechazados'  => implode(' | ', $rechazados),
            'ahora'       => now('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Pacientes antes que consultas, y consultas antes que el resto: las consultas y las demás
     * tablas se traducen con lo que dejan pacientes y consultas. Los borrados, al revés.
     */
    private function enOrden(array $cambios): array
    {
        $prioridad = fn (string $tabla) => ['pacientes' => 0, 'consultas' => 1][$tabla] ?? 2;
        $conPos = [];
        foreach (array_values($cambios) as $i => $c) {
            $tabla = $this->tablas->normalizar((string) ($c['tabla'] ?? ''));
            $borrado = ($c['op'] ?? '') === 'D';
            $conPos[] = [$borrado ? 1 : 0, $borrado ? -$prioridad($tabla) : $prioridad($tabla), $i, $c];
        }
        usort($conPos, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn ($x) => $x[3], $conPos);
    }

    /** @return string 'aplicado' | 'parcial' (se conservaron columnas editadas en el app) | 'omitido' */
    private function aplicar(SyncCarga $carga, array $cambio): string
    {
        $tabla = $this->tablas->normalizar((string) ($cambio['tabla'] ?? ''));
        $op = (string) ($cambio['op'] ?? '');

        if (! in_array($tabla, config('sync_legado.tablas', []), true)) {
            throw new RechazoCambio("tabla no permitida: {$tabla}");
        }
        if (! in_array($op, self::OPERACIONES, true)) {
            throw new RechazoCambio("operación desconocida: {$op}");
        }

        $fecha = isset($cambio['fecha']) ? Carbon::parse($cambio['fecha'], 'UTC') : now('UTC');
        $clave = $this->tablas->normalizarClaves(is_array($cambio['clave'] ?? null) ? $cambio['clave'] : []);
        $fila = is_array($cambio['fila'] ?? null) ? $cambio['fila'] : [];

        if ($op === 'T') {
            return $this->reemplazarTabla($carga, $tabla, is_array($cambio['filas'] ?? null) ? $cambio['filas'] : []);
        }
        if ($clave === []) {
            throw new RechazoCambio('falta la clave de la fila');
        }
        if ($op !== 'D' && $fila === []) {
            throw new RechazoCambio('falta la fila');
        }

        switch ($tabla) {
            case 'pacientes':
                return $op === 'D' ? $this->borrarPaciente($carga, $clave, $fecha) : $this->guardarPaciente($carga, $clave, $fila, $fecha);
            case 'consultas':
                return $op === 'D' ? $this->borrarConsulta($carga, $clave, $fecha) : $this->guardarConsulta($carga, $clave, $fila, $fecha);
            case 'cola':
                return $op === 'D' ? $this->borrarCita($carga, $clave, $fecha) : $this->guardarCita($carga, $clave, $fila, $fecha);
            default:
                return $op === 'D' ? $this->borrarGenerico($carga, $tabla, $clave, $fecha) : $this->guardarGenerico($carga, $tabla, $clave, $fila, $fecha);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Pacientes (historias)                                               */
    /* ------------------------------------------------------------------ */

    private function guardarPaciente(SyncCarga $carga, array $clave, array $fila, Carbon $fecha): string
    {
        $local = $clave['numhistoria'] ?? null;
        if ($local === null) {
            throw new RechazoCambio('pacientes sin numhistoria');
        }
        $claveLocal = TablasLegado::claveHistoria($local);

        $historia = Historia::where('reg_medico', $carga->reg_medico)->where('clave_escritorio', $claveLocal)->first();

        $delApp = $historia ? $this->edicionesDelApp('pacientes', $historia->paciente_id, $fecha) : ['borrado' => false, 'columnas' => []];
        if ($delApp['borrado']) {
            return 'omitido';
        }
        $fila = array_diff_key($this->tablas->normalizarClaves($fila), array_flip($delApp['columnas']));

        $numApi = $historia ? $historia->numhistoria : $this->numeroHistoriaLibre($carga, $local);
        $ignoradas = [];
        $cambiadas = [];
        $historia = (new PacientesLegado($this->tablas))->guardar($carga, $fila, $numApi, $claveLocal, $ignoradas, $cambiadas);
        $this->anotarEdiciones($carga, 'pacientes', $historia->paciente_id, $cambiadas, $fecha);

        return $delApp['columnas'] === [] ? 'aplicado' : 'parcial';
    }

    /**
     * Borrar un paciente en el escritorio borra SU relación con este médico y su historia, no el
     * paciente (puede ser paciente de otros médicos). El app lo saca de la lista por `sync_changes`.
     */
    private function borrarPaciente(SyncCarga $carga, array $clave, Carbon $fecha): string
    {
        $historia = Historia::where('reg_medico', $carga->reg_medico)
            ->where('clave_escritorio', TablasLegado::claveHistoria($clave['numhistoria'] ?? 0))
            ->first();
        if (! $historia) {
            return 'aplicado'; // ya no estaba
        }
        if ($this->edicionesDelApp('pacientes', $historia->paciente_id, $fecha)['columnas'] !== []) {
            return 'omitido'; // el app lo editó después: gana la edición
        }

        MedicoPaciente::where('medico_id', $carga->medico_id)->where('paciente_id', $historia->paciente_id)->delete();
        SyncChange::create([
            'reg_medico'  => $carga->reg_medico,
            'table_name'  => 'pacientes',
            'record_id'   => $historia->paciente_id,
            'operation'   => 'deleted',
            'occurred_at' => now(),
            'source'      => 'escritorio',
        ]);
        $historia->delete();

        return 'aplicado';
    }

    /** El mismo número del escritorio si está libre en el API para este médico; si no, el siguiente. */
    private function numeroHistoriaLibre(SyncCarga $carga, $local): string
    {
        $local = (int) $local;
        $ocupado = Historia::where('reg_medico', $carga->reg_medico)->where('numhistoria', (string) $local)->exists()
            || MedicoPaciente::where('medico_id', $carga->medico_id)->where('numhistoria', (string) $local)->exists();
        if (! $ocupado) {
            return (string) $local;
        }

        $maximo = Historia::where('reg_medico', $carga->reg_medico)->pluck('numhistoria')
            ->merge(MedicoPaciente::where('medico_id', $carga->medico_id)->pluck('numhistoria'))
            ->map(fn ($n) => (int) $n)
            ->max();

        return (string) (((int) $maximo) + 1);
    }

    /* ------------------------------------------------------------------ */
    /* Consultas                                                           */
    /* ------------------------------------------------------------------ */

    private function guardarConsulta(SyncCarga $carga, array $clave, array $fila, Carbon $fecha): string
    {
        $h = $clave['numhistoria'] ?? null;
        $c = $clave['nroconsulta'] ?? null;
        if ($h === null || $c === null) {
            throw new RechazoCambio('consultas sin numhistoria/nroconsulta');
        }
        $claveLocal = TablasLegado::claveConsulta($h, $c);
        $hApi = $this->historiaApi($carga, $h);

        $consulta = Consulta::where('reg_medico', $carga->reg_medico)->where('clave_escritorio', $claveLocal)->first();

        $ignoradas = [];
        $datos = $this->tablas->filtrar('consultas', $this->tablas->normalizarClaves($fila), $ignoradas);
        $datos['numhistoria'] = $hApi;
        $datos['nroconsulta'] = $consulta ? $consulta->nroconsulta : $this->numeroConsultaLibre($carga, $hApi, $c);
        $datos['reg_medico'] = $carga->reg_medico;
        if ($this->tablas->tieneColumna('consultas', 'medico_id')) {
            $datos['medico_id'] = $carga->medico_id;
        }
        $datos['clave_escritorio'] = $claveLocal;
        $datos['updated_at'] = now();

        if ($consulta) {
            DB::table('consultas')->where('id', $consulta->id)->update($datos);
        } else {
            $datos['created_at'] = now();
            DB::table('consultas')->insert($datos);
        }

        return 'aplicado';
    }

    private function borrarConsulta(SyncCarga $carga, array $clave, Carbon $fecha): string
    {
        $consulta = Consulta::where('reg_medico', $carga->reg_medico)
            ->where('clave_escritorio', TablasLegado::claveConsulta($clave['numhistoria'] ?? 0, $clave['nroconsulta'] ?? 0))
            ->first();
        if (! $consulta) {
            return 'aplicado';
        }
        DB::table('consultas')->where('id', $consulta->id)->delete();
        // El app guarda las consultas en su copia local y agrupa por consulta los documentos que
        // imprime (Paso 25): sin esto, una consulta borrada en el escritorio seguiría en el teléfono.
        SyncChange::create([
            'reg_medico'  => $carga->reg_medico,
            'table_name'  => 'consultas',
            'record_id'   => $consulta->id,
            'operation'   => 'deleted',
            'occurred_at' => now(),
            'source'      => 'escritorio',
        ]);

        return 'aplicado';
    }

    private function numeroConsultaLibre(SyncCarga $carga, string $hApi, $local): int
    {
        $base = Consulta::where('reg_medico', $carga->reg_medico)->where('numhistoria', $hApi);
        if (! (clone $base)->where('nroconsulta', (int) $local)->exists()) {
            return (int) $local;
        }

        return ((int) (clone $base)->max('nroconsulta')) + 1;
    }

    /* ------------------------------------------------------------------ */
    /* Citas                                                               */
    /* ------------------------------------------------------------------ */

    private function guardarCita(SyncCarga $carga, array $clave, array $fila, Carbon $fecha): string
    {
        if (! isset($clave['fecha'], $clave['hora_ini'])) {
            throw new RechazoCambio('cola sin fecha/hora_ini');
        }
        $claveLocal = TablasLegado::claveCola($clave['fecha'], $clave['hora_ini']);

        $cita = Cola::where('reg_medico', $carga->reg_medico)->where('clave_escritorio', $claveLocal)->first();

        $delApp = $cita ? $this->edicionesDelApp('cola', $cita->id, $fecha) : ['borrado' => false, 'columnas' => []];
        if ($delApp['borrado']) {
            return 'omitido';
        }

        $ignoradas = [];
        // La `estado` del escritorio ("factura elaborada") no se escribe en `cola.estado`
        // (confirmación en AppDDR): se traduce acá. Ver EstadoCita y WEB-2.12.
        $delEscritorio = EstadoCita::aAppDdr($this->tablas->normalizarClaves($fila));
        $datos = $this->traducir($carga, $this->tablas->filtrar('cola', $delEscritorio, $ignoradas));
        $datos = array_diff_key($datos, array_flip($delApp['columnas']));
        $datos['reg_medico'] = $carga->reg_medico;
        $datos['clave_escritorio'] = $claveLocal;
        $datos['updated_at'] = now();

        if ($cita) {
            $antes = (array) DB::table('cola')->where('id', $cita->id)->first();
            DB::table('cola')->where('id', $cita->id)->update($datos);
            $this->anotarEdiciones($carga, 'cola', $cita->id, $this->distintas($antes, $datos), $fecha);
        } else {
            // El escritorio no confirma citas: nace sin confirmar (puede nacer facturada).
            $datos['estado'] = Cola::ESTADO_NO_CONFIRMADA;
            $datos['created_at'] = now();
            DB::table('cola')->insert($datos);
        }

        return $delApp['columnas'] === [] ? 'aplicado' : 'parcial';
    }

    /** Con el modelo, para que quede en `sync_changes` y el app también la borre (Cola::booted). */
    private function borrarCita(SyncCarga $carga, array $clave, Carbon $fecha): string
    {
        if (! isset($clave['fecha'], $clave['hora_ini'])) {
            throw new RechazoCambio('cola sin fecha/hora_ini');
        }
        $cita = Cola::where('reg_medico', $carga->reg_medico)
            ->where('clave_escritorio', TablasLegado::claveCola($clave['fecha'], $clave['hora_ini']))
            ->first();
        if (! $cita) {
            return 'aplicado';
        }
        if ($this->edicionesDelApp('cola', $cita->id, $fecha)['columnas'] !== []) {
            return 'omitido'; // el app la editó después: gana la edición
        }
        $cita->delete();

        return 'aplicado';
    }

    /* ------------------------------------------------------------------ */
    /* Resto de las tablas                                                 */
    /* ------------------------------------------------------------------ */

    private function guardarGenerico(SyncCarga $carga, string $tabla, array $clave, array $fila, Carbon $fecha): string
    {
        $clave = $this->traducir($carga, $clave);
        $existentes = $this->filasPorClave($carga, $tabla, $clave)->get();

        $ignoradas = [];
        $datos = $this->traducir($carga, $this->tablas->filtrar($tabla, $this->tablas->normalizarClaves($fila), $ignoradas));
        $datos['reg_medico'] = $carga->reg_medico;
        if ($this->tablas->tieneColumna($tabla, 'medico_id')) {
            $datos['medico_id'] = $carga->medico_id;
        }
        if ($this->tablas->tieneColumna($tabla, 'updated_at')) {
            $datos['updated_at'] = now();
        }

        if ($existentes->isNotEmpty()) {
            $this->filasPorClave($carga, $tabla, $clave)->update($datos);
        } else {
            if ($this->tablas->tieneColumna($tabla, 'created_at')) {
                $datos['created_at'] = now();
            }
            DB::table($tabla)->insert($datos);
        }

        return 'aplicado';
    }

    private function borrarGenerico(SyncCarga $carga, string $tabla, array $clave, Carbon $fecha): string
    {
        $clave = $this->traducir($carga, $clave);

        // Las tablas que el app guarda en su copia local se enteran del borrado por `sync_changes`
        // (como `pacientes` y `cola`). Sin esto, un reposo borrado en el escritorio se seguiría
        // imprimiendo en el teléfono (Paso 25).
        if (in_array($tabla, config('sync_legado.borrados_al_app', []), true)) {
            foreach ($this->filasPorClave($carga, $tabla, $clave)->pluck('id') as $id) {
                SyncChange::create([
                    'reg_medico'  => $carga->reg_medico,
                    'table_name'  => $tabla,
                    'record_id'   => $id,
                    'operation'   => 'deleted',
                    'occurred_at' => now(),
                    'source'      => 'escritorio',
                ]);
            }
        }
        $this->filasPorClave($carga, $tabla, $clave)->delete();

        return 'aplicado';
    }

    /** Tablas sin clave primaria: el escritorio manda la tabla entera y reemplaza la del médico. */
    private function reemplazarTabla(SyncCarga $carga, string $tabla, array $filas): string
    {
        $ahora = now();
        $limpias = [];
        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $ignoradas = [];
            $limpia = $this->traducir($carga, $this->tablas->filtrar($tabla, $this->tablas->normalizarClaves($fila), $ignoradas));
            $limpia['reg_medico'] = $carga->reg_medico;
            foreach (['created_at', 'updated_at'] as $marca) {
                if ($this->tablas->tieneColumna($tabla, $marca)) {
                    $limpia[$marca] = $ahora;
                }
            }
            $limpias[] = $limpia;
        }

        // Las filas que creó el app (y que el escritorio todavía no tiene) no se borran.
        $borrar = DB::table($tabla)->where('reg_medico', $carga->reg_medico);
        $tablaDelApp = config("sync_legado.sin_clave_con_altas_del_app.{$tabla}");
        if ($tablaDelApp) {
            $delApp = SyncChange::where('table_name', $tablaDelApp)->where('operation', 'created')
                ->where('source', 'mobile')->where('reg_medico', $carga->reg_medico)->pluck('record_id');
            $borrar->whereNotIn('id', $delApp);
        }
        $borrar->delete();
        foreach ($limpias as $limpia) {
            DB::table($tabla)->insert($limpia);
        }

        return 'aplicado';
    }

    private function filasPorClave(SyncCarga $carga, string $tabla, array $clave)
    {
        $consulta = DB::table($tabla)->where('reg_medico', $carga->reg_medico);
        foreach ($clave as $columna => $valor) {
            if (! $this->tablas->tieneColumna($tabla, $columna)) {
                throw new RechazoCambio("la columna de la clave {$columna} no existe en {$tabla}");
            }
            $consulta->where($columna, $valor);
        }

        return $consulta;
    }

    /* ------------------------------------------------------------------ */
    /* Traducción de números del escritorio a números del API              */
    /* ------------------------------------------------------------------ */

    /**
     * Reemplaza los números de historia (y de consulta, si viene junto a uno de historia) del
     * escritorio por los del API. Si la historia o la consulta todavía no subió, rechaza: el
     * escritorio lo reintenta cuando haya subido.
     */
    private function traducir(SyncCarga $carga, array $valores): array
    {
        $colH = $this->primeraColumna($valores, TablasLegado::COLUMNAS_HISTORIA);
        if ($colH === null) {
            return $valores;
        }
        $hLocal = $valores[$colH];
        $colC = $this->primeraColumna($valores, TablasLegado::COLUMNAS_CONSULTA);

        if ($colC !== null) {
            [$hApi, $cApi] = $this->consultaApi($carga, $hLocal, $valores[$colC]);
            $valores[$colC] = $cApi;
        } else {
            $hApi = $this->historiaApi($carga, $hLocal);
        }
        foreach (TablasLegado::COLUMNAS_HISTORIA as $columna) {
            if (array_key_exists($columna, $valores) && $valores[$columna] !== null) {
                $valores[$columna] = $hApi;
            }
        }

        return $valores;
    }

    private function primeraColumna(array $valores, array $candidatas): ?string
    {
        foreach ($candidatas as $columna) {
            if (array_key_exists($columna, $valores) && $valores[$columna] !== null && $valores[$columna] !== '') {
                return $columna;
            }
        }

        return null;
    }

    private function historiaApi(SyncCarga $carga, $local): string
    {
        $clave = TablasLegado::claveHistoria($local);
        if (! array_key_exists($clave, $this->historias)) {
            $this->historias[$clave] = Historia::where('reg_medico', $carga->reg_medico)
                ->where('clave_escritorio', $clave)->value('numhistoria');
        }
        if ($this->historias[$clave] === null) {
            throw new RechazoCambio("la historia {$clave} del escritorio todavía no está en el API");
        }

        return (string) $this->historias[$clave];
    }

    /** @return array{0:string, 1:int} */
    private function consultaApi(SyncCarga $carga, $hLocal, $cLocal): array
    {
        $clave = TablasLegado::claveConsulta($hLocal, $cLocal);
        if (! array_key_exists($clave, $this->consultas)) {
            $consulta = Consulta::where('reg_medico', $carga->reg_medico)->where('clave_escritorio', $clave)->first();
            $this->consultas[$clave] = $consulta ? [(string) $consulta->numhistoria, (int) $consulta->nroconsulta] : null;
        }
        if ($this->consultas[$clave] === null) {
            throw new RechazoCambio("la consulta {$clave} del escritorio todavía no está en el API");
        }

        return $this->consultas[$clave];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Lo que el app hizo con una fila DESPUÉS del cambio del escritorio, según `sync_changes`.
     *
     * @return array{borrado: bool, columnas: string[]}
     */
    private function edicionesDelApp(string $tabla, $recordId, Carbon $fechaCambio): array
    {
        $cambios = SyncChange::where('table_name', $tabla)
            ->where('record_id', $recordId)
            ->where('source', 'mobile')
            ->where('occurred_at', '>', $fechaCambio)
            ->get(['operation', 'column_name']);

        return [
            'borrado'  => $cambios->contains('operation', 'deleted'),
            'columnas' => $cambios->where('operation', 'updated')->pluck('column_name')->filter()->unique()->values()->all(),
        ];
    }

    /** Anota las columnas que cambió el escritorio, para que el app no las pise con algo más viejo. */
    private function anotarEdiciones(SyncCarga $carga, string $tabla, $recordId, array $columnas, Carbon $fecha): void
    {
        foreach ($columnas as $columna => $valor) {
            SyncChange::create([
                'reg_medico'  => $carga->reg_medico,
                'table_name'  => $tabla,
                'record_id'   => $recordId,
                'operation'   => 'updated',
                'column_name' => $columna,
                'value'       => is_scalar($valor) || $valor === null ? $valor : json_encode($valor),
                'occurred_at' => $fecha,
                'source'      => 'escritorio',
            ]);
        }
    }

    /** Columnas de $nuevos cuyo valor cambió respecto de $antes (sin marcas de tiempo ni claves internas). */
    private function distintas(array $antes, array $nuevos): array
    {
        $cambiadas = [];
        foreach ($nuevos as $columna => $valor) {
            if (in_array($columna, ['updated_at', 'created_at', 'reg_medico', 'clave_escritorio'], true)) {
                continue;
            }
            if (! array_key_exists($columna, $antes) || (string) $antes[$columna] !== (string) $valor) {
                $cambiadas[$columna] = $valor;
            }
        }

        return $cambiadas;
    }
}
