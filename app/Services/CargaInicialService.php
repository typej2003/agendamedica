<?php

namespace App\Services;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncCarga;
use App\Models\SyncCargaTabla;
use App\Sync\Escritorio\EstadoCita;
use App\Sync\Escritorio\PacientesLegado;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carga inicial completa del escritorio PowerBuilder al API (Fase 1 del sync del legado).
 *
 * Reglas (decididas con el usuario, 2026-09-27):
 *  - Solo se hace si el `reg_medico` está LIMPIO en el API: es la primera carga. Si ya hay datos,
 *    se rechaza; para reintentar desde cero se usa otro `reg_medico`.
 *  - Una carga que se cortó a mitad de camino se RETOMA: el API guarda cuántas filas recibió de
 *    cada tabla y solo acepta el lote que sigue. Reenviar un lote ya recibido no duplica nada.
 *  - Solo se aceptan las tablas de `config/sync_legado.php`.
 *
 * Por qué el reanudar es por POSICIÓN y no por clave primaria: la carga es sobre un médico limpio,
 * así que no hay nada con qué chocar; basta con no aplicar dos veces el mismo tramo. El escritorio
 * lee cada tabla ordenada por su clave primaria, así que la posición es estable entre intentos.
 */
class CargaInicialService
{
    /** Tablas del API (no del legado) que también indican que el médico ya tiene datos. */
    private const TABLAS_DEL_API_CON_DATOS = ['medico_pacientes', 'historias'];

    public function __construct(private TablasLegado $tablas)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Médico y limpieza                                                   */
    /* ------------------------------------------------------------------ */

    public function medicoDe(string $regMedico): ?Medico
    {
        $registro = MedicoRegistro::where('reg_medico', $regMedico)->first();
        if ($registro) {
            return Medico::find($registro->medico_id);
        }

        return Medico::where('reg_medico', $regMedico)->first();
    }

    /**
     * Tablas donde el médico ya tiene filas. Vacío = limpio.
     *
     * @return string[]
     */
    public function tablasConDatos(string $regMedico): array
    {
        $excluidas = config('sync_legado.no_cuentan_para_limpieza', []);
        $tablas = array_merge(
            array_diff(config('sync_legado.tablas', []), $excluidas),
            self::TABLAS_DEL_API_CON_DATOS
        );

        $conDatos = [];
        foreach ($tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! in_array('reg_medico', $this->tablas->columnasDe($tabla), true)) {
                continue;
            }
            if (DB::table($tabla)->where('reg_medico', $regMedico)->exists()) {
                $conDatos[] = $tabla;
            }
        }

        return $conDatos;
    }

    /* ------------------------------------------------------------------ */
    /* Iniciar / retomar                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, int>  $anunciadas  tabla => filas que tiene el escritorio
     * @return array{http:int, cuerpo:array}
     */
    public function iniciar(string $regMedico, array $anunciadas): array
    {
        $medico = $this->medicoDe($regMedico);
        if (! $medico) {
            return $this->error(404, 'medico_no_registrado',
                "El médico {$regMedico} no está registrado en el API. Hay que darlo de alta antes de la carga inicial.");
        }

        $carga = SyncCarga::where('reg_medico', $regMedico)->first();

        if ($carga && $carga->estado === SyncCarga::COMPLETA) {
            return $this->error(409, 'carga_ya_completa',
                "La carga inicial de {$regMedico} ya se completó el {$carga->finalizada_at}. Desde acá se sincronizan solo los cambios.");
        }

        if (! $carga) {
            $conDatos = $this->tablasConDatos($regMedico);
            if ($conDatos !== []) {
                return $this->error(409, 'medico_con_datos',
                    "El médico {$regMedico} ya tiene datos en el API (" . implode(', ', $conDatos) . '). '
                    . 'La carga inicial solo se hace sobre un médico sin datos: usá otro reg_medico.');
            }

            $carga = SyncCarga::create([
                'reg_medico'  => $regMedico,
                'medico_id'   => $medico->id,
                'estado'      => SyncCarga::EN_CURSO,
                'iniciada_at' => now(),
            ]);
        }

        // Normalizar lo anunciado y separar lo que el API no acepta.
        $permitidas = config('sync_legado.tablas', []);
        $aceptadas = [];
        $ignoradas = [];
        foreach ($anunciadas as $tabla => $filas) {
            $tabla = $this->tablas->normalizar((string) $tabla);
            if (in_array($tabla, $permitidas, true) && Schema::hasTable($tabla)) {
                $aceptadas[$tabla] = max(0, (int) $filas);
            } elseif ((int) $filas > 0) {
                $ignoradas[] = $tabla;
            }
        }

        foreach ($aceptadas as $tabla => $filas) {
            $registro = SyncCargaTabla::firstOrNew(['sync_carga_id' => $carga->id, 'tabla' => $tabla]);
            $registro->filas_esperadas = $filas;
            $registro->save();
        }

        // Orden: el de la lista blanca (pacientes primero), solo las anunciadas.
        $orden = array_values(array_filter($permitidas, fn ($t) => array_key_exists($t, $aceptadas)));
        $recibidas = SyncCargaTabla::where('sync_carga_id', $carga->id)->pluck('filas_recibidas', 'tabla');

        $tablas = [];
        foreach ($orden as $tabla) {
            $tablas[$tabla] = (int) ($recibidas[$tabla] ?? 0);
        }

        return ['http' => 200, 'cuerpo' => [
            'ok'        => true,
            'carga_id'  => $carga->id,
            'estado'    => $carga->estado,
            'retomada'  => $carga->wasRecentlyCreated ? 0 : 1,
            'orden'     => implode(',', $orden),
            'tablas'    => $tablas,
            'ignoradas' => implode(',', $ignoradas),
        ]];
    }

    /* ------------------------------------------------------------------ */
    /* Lotes                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{http:int, cuerpo:array}
     */
    public function recibirLote(SyncCarga $carga, string $tabla, int $desde, array $filas): array
    {
        $tabla = $this->tablas->normalizar($tabla);

        if ($carga->estado !== SyncCarga::EN_CURSO) {
            return $this->error(409, 'carga_cerrada', 'La carga inicial ya está cerrada.');
        }

        $registro = SyncCargaTabla::where('sync_carga_id', $carga->id)->where('tabla', $tabla)->first();
        if (! $registro) {
            return $this->error(422, 'tabla_no_anunciada',
                "La tabla {$tabla} no se anunció al iniciar la carga o no está permitida.");
        }

        $cantidad = count($filas);
        $recibidas = (int) $registro->filas_recibidas;

        // Lote ya aplicado (reintento después de un corte): se confirma sin volver a escribir.
        if ($cantidad > 0 && $desde + $cantidad <= $recibidas) {
            return $this->okLote($tabla, $recibidas, 1);
        }

        // Hueco o solapamiento: el escritorio tiene que seguir desde donde dice el API.
        if ($desde !== $recibidas) {
            return ['http' => 409, 'cuerpo' => [
                'ok'       => false,
                'error'    => 'posicion_incorrecta',
                'mensaje'  => "Se esperaba el lote de {$tabla} desde la fila {$recibidas} y llegó desde {$desde}.",
                'tabla'    => $tabla,
                'esperado' => $recibidas,
            ]];
        }

        $ignoradas = [];
        $omitidas = 0;

        DB::transaction(function () use ($carga, $tabla, $filas, &$ignoradas, &$omitidas) {
            switch ($tabla) {
                case 'pacientes':
                    $omitidas = $this->guardarPacientes($carga, $filas, $ignoradas);
                    break;
                case 'evolucion':
                    $this->guardarEvolucion($carga, $filas, $ignoradas);
                    break;
                default:
                    $this->insertarGenerico($carga, $tabla, $filas, $ignoradas);
            }
        });

        $registro->filas_recibidas = $recibidas + $cantidad;
        $registro->filas_omitidas += $omitidas;
        if ($ignoradas !== []) {
            $previas = array_filter(explode(',', (string) $registro->columnas_ignoradas));
            $registro->columnas_ignoradas = implode(',', array_unique(array_merge($previas, $ignoradas)));
        }
        $registro->save();

        return $this->okLote($tabla, $registro->filas_recibidas, 0);
    }

    /* ------------------------------------------------------------------ */
    /* Finalizar                                                           */
    /* ------------------------------------------------------------------ */

    /** @return array{http:int, cuerpo:array} */
    public function finalizar(SyncCarga $carga): array
    {
        $tablas = SyncCargaTabla::where('sync_carga_id', $carga->id)->orderBy('tabla')->get();

        $pendientes = $tablas->filter(fn ($t) => $t->filas_recibidas < $t->filas_esperadas);
        if ($pendientes->isNotEmpty()) {
            return ['http' => 409, 'cuerpo' => [
                'ok'         => false,
                'error'      => 'carga_incompleta',
                'mensaje'    => 'Faltan filas por subir en: ' . $pendientes->pluck('tabla')->implode(', '),
                'pendientes' => $pendientes->mapWithKeys(fn ($t) => [$t->tabla => $t->filas_esperadas - $t->filas_recibidas])->all(),
            ]];
        }

        if ($carga->estado !== SyncCarga::COMPLETA) {
            $carga->estado = SyncCarga::COMPLETA;
            $carga->finalizada_at = now();
            $carga->save();
        }

        $conIgnoradas = $tablas->filter(fn ($t) => $t->columnas_ignoradas);

        return ['http' => 200, 'cuerpo' => [
            'ok'                 => true,
            'estado'             => $carga->estado,
            'tablas'             => $tablas->count(),
            'filas'              => (int) $tablas->sum('filas_recibidas'),
            'filas_omitidas'     => (int) $tablas->sum('filas_omitidas'),
            'columnas_ignoradas' => $conIgnoradas->map(fn ($t) => $t->tabla . ':' . $t->columnas_ignoradas)->implode(' | '),
        ]];
    }

    /* ------------------------------------------------------------------ */
    /* Escritura por tabla                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Tabla del legado sin tratamiento especial: se inserta tal cual, con el `reg_medico` de la
     * carga. Las columnas que el API no tiene se informan (no se pierden en silencio).
     */
    private function insertarGenerico(SyncCarga $carga, string $tabla, array $filas, array &$ignoradas): void
    {
        $columnas = $this->tablas->columnasDe($tabla);
        $ahora = now();
        $limpias = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $limpia = $this->tablas->filtrar($tabla, $fila, $ignoradas);
            if ($tabla === 'cola') {
                // El escritorio trae `estado = 1` de facturación, no de confirmación (WEB-2.12).
                $limpia = EstadoCita::nuevaDeEscritorio($limpia);
            }
            $limpia['reg_medico'] = $carga->reg_medico;
            // Clave de la fila en el escritorio: con ella la sincronización de cambios reconoce
            // consultas y citas aunque sus números o su hora cambien (bridge/DISENO-FASE-2.md § 4).
            if (in_array('clave_escritorio', $columnas, true)) {
                $limpia['clave_escritorio'] = $this->claveEscritorio($tabla, $limpia);
            }
            if (in_array('medico_id', $columnas, true)) {
                $limpia['medico_id'] = $carga->medico_id;
            }
            if (in_array('created_at', $columnas, true)) {
                $limpia['created_at'] = $ahora;
            }
            if (in_array('updated_at', $columnas, true)) {
                $limpia['updated_at'] = $ahora;
            }
            $limpias[] = $limpia;
        }

        // insert() exige las mismas claves en todas las filas.
        $claves = [];
        foreach ($limpias as $f) {
            $claves += array_flip(array_keys($f));
        }
        $claves = array_keys($claves);
        $plantilla = array_fill_keys($claves, null);
        foreach ($limpias as $i => $f) {
            $limpias[$i] = array_merge($plantilla, $f);
        }

        // Tope de parámetros por sentencia (SQLite: 32766; MySQL: 65535).
        $porSentencia = max(1, intdiv(10000, max(1, count($claves))));
        foreach (array_chunk($limpias, $porSentencia) as $tramo) {
            DB::table($tabla)->insert($tramo);
        }
    }

    /**
     * Configuración del médico. Puede existir desde que se registró en el app, así que se
     * actualiza por (reg_medico, clave) en vez de insertar. No se suben credenciales ni el logo.
     */
    private function guardarEvolucion(SyncCarga $carga, array $filas, array &$ignoradas): void
    {
        $columnas = $this->tablas->columnasDe('evolucion');
        foreach ($filas as $fila) {
            if (! is_array($fila) || ! isset($fila['clave'])) {
                continue;
            }
            $datos = $this->tablas->filtrar('evolucion', $fila, $ignoradas);
            unset($datos['clave']);
            if (in_array('updated_at', $columnas, true)) {
                $datos['updated_at'] = now();
            }
            $existe = DB::table('evolucion')->where('reg_medico', $carga->reg_medico)->where('clave', $fila['clave'])->exists();
            if (! $existe && in_array('created_at', $columnas, true)) {
                $datos['created_at'] = now();
            }
            DB::table('evolucion')->updateOrInsert(
                ['reg_medico' => $carga->reg_medico, 'clave' => $fila['clave']],
                $datos
            );
        }
    }

    /**
     * Pacientes del legado -> paciente + relación con el médico + historia (ver PacientesLegado).
     * En la carga el número de historia es el mismo en los dos lados.
     *
     * @return int filas omitidas
     */
    private function guardarPacientes(SyncCarga $carga, array $filas, array &$ignoradas): int
    {
        $pacientes = new PacientesLegado($this->tablas);
        $omitidas = 0;

        foreach ($filas as $fila) {
            if (! is_array($fila) || ! isset($fila['numhistoria'])) {
                $omitidas++;
                continue;
            }
            $pacientes->guardar($carga, $fila, $fila['numhistoria'], TablasLegado::claveHistoria($fila['numhistoria']), $ignoradas);
        }

        return $omitidas;
    }

    /** `clave_escritorio` de una fila recién llegada del escritorio (consultas y cola). */
    private function claveEscritorio(string $tabla, array $fila): ?string
    {
        if ($tabla === 'consultas' && isset($fila['numhistoria'], $fila['nroconsulta'])) {
            return TablasLegado::claveConsulta($fila['numhistoria'], $fila['nroconsulta']);
        }
        if ($tabla === 'cola' && isset($fila['fecha'], $fila['hora_ini'])) {
            return TablasLegado::claveCola($fila['fecha'], $fila['hora_ini']);
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    private function okLote(string $tabla, int $recibidas, int $duplicado): array
    {
        return ['http' => 200, 'cuerpo' => [
            'ok'        => true,
            'tabla'     => $tabla,
            'recibidas' => $recibidas,
            'duplicado' => $duplicado,
        ]];
    }

    private function error(int $http, string $codigo, string $mensaje): array
    {
        return ['http' => $http, 'cuerpo' => ['ok' => false, 'error' => $codigo, 'mensaje' => $mensaje]];
    }
}
