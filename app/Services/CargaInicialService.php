<?php

namespace App\Services;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncCarga;
use App\Models\SyncCargaTabla;
use App\Sync\Escritorio\AtencionCita;
use App\Sync\Escritorio\EstadoCita;
use App\Sync\Escritorio\PacientesLegado;
use App\Sync\Escritorio\TablasDelSync;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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

    public function __construct(private TablasLegado $tablas, private TablasDelSync $sync)
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
     * Mira las tablas de **su especialidad** (WEB-7.3): pediatría no tiene por qué revisar las de
     * ginecología, y al revés. `$medico` es opcional para no romper a quien ya llamaba al método.
     *
     * @return string[]
     */
    public function tablasConDatos(string $regMedico, ?Medico $medico = null): array
    {
        $excluidas = config('sync_legado.no_cuentan_para_limpieza', []);
        $tablas = array_merge(
            array_diff($this->sync->paraMedico($medico), $excluidas),
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

        // Normalizar lo anunciado y separar lo que el API no acepta. Lo que se **acepta** es la unión
        // de todas las especialidades (una tabla de otra especialidad no se rechaza); lo que se le
        // **pide** al escritorio, más abajo, es lo de la especialidad de este médico.
        $permitidas = $this->sync->aceptadas();
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

        // Antes de armarle la lista: si este médico no tiene ninguna especialidad vinculada, se la deduce de
        // las tablas que acaba de anunciar y se le asigna. Es la primera sincronización de esa instalación y
        // el técnico no tiene que hacer este paso a mano —sin especialidad el médico no recibe su lista de
        // tablas (recibe la histórica de ginecología) y en la web entra a un consultorio sin módulos—.
        // Con evidencia insuficiente no se asigna nada (ver `especialidadesSegunTablas`).
        $asignadas = $this->vincularEspecialidadDeducida($medico, array_keys($aceptadas));

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
            // Qué especialidad se le vinculó a este médico en esta primera sincronización (vacío si ya
            // tenía o si no se pudo deducir). Queda en el log del escritorio para poder verlo.
            'especialidad' => implode(',', $asignadas),
        ]];
    }

    /**
     * Le vincula al médico la especialidad que se deduce de las tablas que anunció su escritorio, **si no
     * tiene ninguna**. Devuelve los slugs asignados (vacío si no había nada que deducir o ya tenía).
     *
     * Es lo que hace que el técnico no tenga que asignar la especialidad a mano en la primera
     * sincronización de una instalación nueva: el escritorio anuncia todas sus tablas antes de subir nada y
     * las distintivas de cada especialidad están en su manifiesto.
     *
     * Sólo se asigna cuando hay **evidencia positiva** (ver `TablasDelSync::especialidadesSegunTablas`) y
     * sólo si el médico no tenía ninguna: si ya tiene (por el panel, o porque la instalación atiende dos
     * especialidades) no se le toca nada.
     *
     * @param  array<int, string>  $tablasAnunciadas
     * @return array<int, string>
     */
    private function vincularEspecialidadDeducida(?Medico $medico, array $tablasAnunciadas): array
    {
        if (! $medico || $this->sync->especialidadesDe($medico) !== []) {
            return [];
        }

        $asignadas = [];
        foreach ($this->sync->especialidadesSegunTablas($tablasAnunciadas) as $slug) {
            $especialidad = Specialty::where('slug', $slug)->first();
            if (! $especialidad) {
                // El catálogo no la tiene: falta `php artisan especialidades:sembrar` en esa base.
                Log::warning('Carga inicial: la especialidad deducida no está en el catálogo', [
                    'reg_medico' => $medico->reg_medico, 'especialidad' => $slug,
                ]);
                continue;
            }

            $medico->specialties()->syncWithoutDetaching([$especialidad->id]);
            $asignadas[] = $slug;
        }

        if ($asignadas !== []) {
            $medico->load('specialties');   // el resto del request ya la ve
            Log::info('Carga inicial: especialidad vinculada desde las tablas del escritorio', [
                'reg_medico' => $medico->reg_medico, 'especialidades' => $asignadas,
            ]);
        }

        return $asignadas;
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

        // Las tablas de las que salen los **pacientes** de este médico: `pacientes` siempre y, si su
        // especialidad declara otra (pediatría: `paciente_chamo`), también esa. Ver
        // `TablasDelSync::pacientesDe()`.
        $pacientes = $this->sync->pacientesDe($this->medicoDe($carga->reg_medico));

        DB::transaction(function () use ($carga, $tabla, $filas, $pacientes, &$ignoradas, &$omitidas) {
            if (array_key_exists($tabla, $pacientes)) {
                // El camino de pacientes: crea el paciente, su relación con el médico y su historia. Es lo
                // que después leen la web y el app.
                $omitidas = $this->guardarPacientes($carga, $tabla, $filas, $ignoradas, $pacientes[$tabla]);

                // Cuando la tabla de pacientes es propia de la especialidad, además se guarda tal cual: sus
                // columnas que no existen en `pacientes` (alergia, notas, foto, representante, familia…)
                // viven en su propia tabla del API y las usa el app de pediatría.
                if ($tabla !== 'pacientes') {
                    $this->insertarGenerico($carga, $tabla, $filas, $ignoradas);
                }

                return;
            }

            switch ($tabla) {
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
                // El escritorio trae `estado = 1` de facturación, no de confirmación (WEB-2.12), y su
                // `atendido` con cuatro valores (0/1/2/3), no el booleano de AppDDR (WEB-2.13).
                $limpia = EstadoCita::nuevaDeEscritorio($limpia);
                $limpia = AtencionCita::aAppDdr($limpia);
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
     * `$tabla` es de dónde vienen (para el mensaje de error) y `$mapa` el mapeo de columnas del manifiesto
     * (`sync.pacientes.columnas`), vacío cuando la tabla es `pacientes`.
     *
     * @return int filas omitidas
     */
    private function guardarPacientes(SyncCarga $carga, string $tabla, array $filas, array &$ignoradas, array $mapa = []): int
    {
        $pacientes = new PacientesLegado($this->tablas);
        $omitidas = 0;

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                $omitidas++;
                continue;
            }

            $fila = $this->tablas->traducirPaciente($fila, $mapa);
            if (! isset($fila['numhistoria'])) {
                $omitidas++;
                continue;
            }
            $pacientes->guardar($carga, $fila, $fila['numhistoria'], TablasLegado::claveHistoria($fila['numhistoria']), $ignoradas);
        }

        // Un lote del que no se pudo usar NINGUNA fila no puede responder "recibido": así fue como la
        // carga de pediatría terminó "Completa" con 1,19 M de filas y **0 pacientes**, sin un solo error
        // visible (el escritorio anunciaba `pacientes` vacía y nadie miraba el resultado). Ver la trampa.
        if ($filas !== [] && $omitidas === count($filas)) {
            throw new RuntimeException(
                "No se pudo usar ninguna de las " . count($filas) . " filas de {$tabla}: "
                . 'falta la columna del número de historia (' . implode('/', TablasLegado::COLUMNAS_HISTORIA) . ').'
            );
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
            // `fecha|hora_ini`: la cita se identifica por su historia, no por esta clave (trampa 109).
            return TablasLegado::claveCola($fila['fecha'], $fila['hora_ini']);
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Pacientes desde una tabla que ya está en el API                      */
    /* ------------------------------------------------------------------ */

    /**
     * Arma los pacientes (paciente + relación con el médico + historia) a partir de la tabla de pacientes
     * del escritorio que **ya está en el API**, sin pedirle nada al escritorio.
     *
     * Es para reparar una carga que subió esa tabla a su tabla propia pero **no creó las historias**: pasó
     * con pediatría el 2026-10-14 —su `pacientes` está vacía y sus 6.892 chamos quedaron en la tabla
     * `paciente_chamo` sin historia, así que ni la web ni el app tenían pacientes—. También sirve para
     * cualquier especialidad a la que se le agregue la fuente de pacientes **después** de una carga hecha.
     *
     * Corre el **mismo cargador** que la carga inicial (`guardarPacientes`), así lo que quede en la base es
     * idéntico a lo que el sync habría producido; es idempotente (`PacientesLegado` busca por
     * `clave_escritorio` y por cédula, y crea o actualiza).
     *
     * @return array{tablas: array<string, array{filas: int, nuevas: int, existentes: int, omitidas: int}>, creados: int, actualizados: int}
     */
    public function pacientesDesdeLaTablaDelEscritorio(string $regMedico, bool $simular = false, ?string $tabla = null): array
    {
        $medico = $this->medicoDe($regMedico);
        if (! $medico) {
            throw new RuntimeException("El médico {$regMedico} no está registrado en el API.");
        }

        $carga = SyncCarga::where('reg_medico', $regMedico)->first();
        if (! $carga) {
            throw new RuntimeException(
                "El médico {$regMedico} no tiene ninguna carga inicial en el API: sin ella no hay dónde colgar "
                . 'la relación con el médico ni la historia.'
            );
        }

        $declaradas = $this->sync->fuentesDePacientesDeclaradas();

        // Las de su especialidad (`pacientes` no: esa la hizo la carga, y si estuviera vacía el problema es
        // otro). Si se pide una tabla en particular, esa manda.
        $fuentes = array_filter(
            $this->sync->pacientesDe($medico),
            fn ($mapa, $nombre) => $nombre !== 'pacientes',
            ARRAY_FILTER_USE_BOTH
        );
        if ($tabla !== null && $tabla !== '') {
            $fuentes = [$tabla => (array) ($declaradas[$tabla] ?? [])];
        }

        // Y además cualquier tabla de pacientes declarada **que tenga filas de este médico**: el dato está
        // ahí aunque el médico no tenga la especialidad asignada en `medico_specialty` (que es justo lo que
        // pasó en la prueba: la carga subió los 6.892 chamos y el pivote quedó vacío, así que el API no
        // sabía de qué especialidad era).
        foreach ($declaradas as $nombre => $mapa) {
            if (! array_key_exists($nombre, $fuentes)
                && Schema::hasTable($nombre)
                && in_array('reg_medico', $this->tablas->columnasDe($nombre), true)
                && DB::table($nombre)->where('reg_medico', $regMedico)->exists()) {
                $fuentes[$nombre] = $mapa;
            }
        }

        $detalle = [];
        $creados = 0;
        $actualizados = 0;

        foreach ($fuentes as $tabla => $mapa) {
            if (! Schema::hasTable($tabla) || ! in_array('reg_medico', $this->tablas->columnasDe($tabla), true)) {
                $detalle[$tabla] = ['filas' => 0, 'nuevas' => 0, 'existentes' => 0, 'omitidas' => 0];
                continue;
            }

            $filas = 0;
            $nuevas = 0;
            $existentes = 0;
            $omitidas = 0;

            DB::table($tabla)->where('reg_medico', $regMedico)->orderBy('id')->chunk(500, function ($lote) use (
                $carga, $tabla, $mapa, $simular, &$filas, &$nuevas, &$existentes, &$omitidas
            ) {
                $ignoradas = [];

                foreach ($lote as $registro) {
                    $fila = $this->tablas->traducirPaciente((array) $registro, $mapa);
                    // Las marcas de tiempo del espejo no se copian: `updated_at` es lo que el app usa para
                    // el delta y tiene que quedar en "ahora", o el paciente no le bajaría nunca.
                    unset($fila['created_at'], $fila['updated_at']);

                    if (! isset($fila['numhistoria'])) {
                        $omitidas++;
                        continue;
                    }

                    $filas++;
                    $clave = TablasLegado::claveHistoria($fila['numhistoria']);
                    $yaEstaba = \App\Models\Historia::where('reg_medico', $carga->reg_medico)
                        ->where('clave_escritorio', $clave)->exists();
                    $yaEstaba ? $existentes++ : $nuevas++;

                    if (! $simular) {
                        (new PacientesLegado($this->tablas))->guardar(
                            $carga, $fila, $fila['numhistoria'], $clave, $ignoradas
                        );
                    }
                }
            });

            $detalle[$tabla] = ['filas' => $filas, 'nuevas' => $nuevas, 'existentes' => $existentes, 'omitidas' => $omitidas];
            $creados += $nuevas;
            $actualizados += $existentes;
        }

        return ['tablas' => $detalle, 'creados' => $creados, 'actualizados' => $actualizados];
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
