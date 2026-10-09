<?php

namespace App\Sync\Escritorio;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\SyncCarga;
use App\Models\SyncCargaTabla;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Restauración manual **nube → escritorio**: lo inverso de la carga inicial
 * (`App\Services\CargaInicialService`), para cuando la PC del consultorio se perdió, se robó o se
 * formateó y hay que volver a llenar su base SQL Anywhere con lo que ya está en el API.
 *
 * Por qué existe y no se usa una "bajada" normal: `POST /api/sync/cambios/bajar` (Fase 2.B) todavía
 * no está implementado; esto es un camino de UNA SOLA VEZ, con archivos que se llevan a la PC del
 * médico a mano. Ver `bridge/LEEME-restauracion.md` y `bridge/DISENO-FASE-2.md`.
 *
 * Lo que NO se puede recuperar de la nube (no viaja en el sync): las imágenes clínicas (en la base
 * solo vive la ruta del archivo), los usuarios del escritorio (`operadores`, con su clave en texto
 * plano: quedaron fuera de la lista blanca), la clave del sistema y las credenciales de SMS
 * (`evolucion.contrasena`, `sms_user`, `sms_clave`), el logo (`evolucion.logo`) y `recipe.ini`, que
 * es un archivo por PC. Ver `config/sync_legado.php` y `PENDIENTES-POWERBUILDER.md`.
 *
 * Formato de la salida (carpeta):
 *  - `manifiesto.txt`: líneas separadas por tabulador, UTF-8. `tabla<TAB>nombre<TAB>archivo<TAB>columnas<TAB>filas`.
 *  - `datos/<tabla>.tsv`: una fila por línea, valores separados por tabulador. `\N` = NULL y se
 *    escapan `\`, tabulador, CR y LF. Sin encabezado (el manifiesto manda).
 *
 * La intersección de columnas con la base local la hace el aplicador (`bridge/restaurar-escritorio.vbs`):
 * acá se exporta todo lo que el API tiene, salvo las columnas que el escritorio no conoce o que no
 * se deben devolver tal cual (las marcas de AppDDR y lo excluido en `config/sync_legado.php`).
 */
class RestauracionEscritorio
{
    /** Identificador del formato que escribe el manifiesto. */
    public const FORMATO = 'ddr-restauracion-1';

    /** Columnas del API que el escritorio no conoce (o que no se deben devolver tal cual). */
    private const COLUMNAS_DEL_API = ['id', 'clave_escritorio', 'medico_id', 'created_at', 'updated_at'];

    /** Columnas nuevas de AppDDR que no tienen que volver al escritorio. */
    private const COLUMNAS_ESPECIALES = [
        // `estado` y `atendido` vuelven traducidos por EstadoCita/AtencionCita; sus marcas no existen
        // en el legado. `paciente_sinhistoria_id` la agregó el API (ver PENDIENTES-POWERBUILDER.md).
        'cola' => ['facturada_escritorio', 'movida_escritorio', 'paciente_sinhistoria_id'],
        // Paciente: `user_id` es de AppDDR y `password` nunca se subió (PacientesLegado lo saca).
        'pacientes' => ['user_id', 'password'],
    ];

    /** Filas por lectura: las tablas grandes (consultas) tienen TEXT largos y no entran en memoria. */
    private const FILAS_POR_LECTURA = 500;

    public function __construct(private TablasLegado $tablas)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Punto de entrada                                                    */
    /* ------------------------------------------------------------------ */

    public function carpetaPorDefecto(string $regMedico): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'restauracion' . DIRECTORY_SEPARATOR . $this->nombreSeguro($regMedico));
    }

    public function medicoDe(string $regMedico): ?Medico
    {
        $registro = MedicoRegistro::where('reg_medico', $regMedico)->first();
        if ($registro) {
            return Medico::find($registro->medico_id);
        }

        return Medico::where('reg_medico', $regMedico)->first();
    }

    /**
     * Escribe la carpeta de restauración del médico.
     *
     * @param  string[]|null  $soloTablas  para pruebas o restauraciones parciales
     * @param  callable(string, int):void|null  $aviso  (tabla, filas) después de cada tabla
     * @return array{
     *     carpeta: string, archivos: array<string,string>, filas: array<string,int>,
     *     total_filas: int, vacias: string[], omitidas: array<string,string[]>,
     *     notas: string[], carga: array<string,int>
     * }
     */
    public function exportar(string $regMedico, ?string $carpeta = null, ?array $soloTablas = null, ?callable $aviso = null): array
    {
        $carpeta = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $carpeta ?: $this->carpetaPorDefecto($regMedico)), DIRECTORY_SEPARATOR);
        $this->asegurarCarpeta($carpeta . DIRECTORY_SEPARATOR . 'datos');

        $tablas = config('sync_legado.tablas', []);
        if ($soloTablas !== null) {
            $tablas = array_values(array_intersect($tablas, $soloTablas));
        }

        $archivos = [];
        $filas = [];
        $vacias = [];
        $omitidas = [];
        $notas = [];
        $manifiesto = [self::FORMATO, "medico\t{$regMedico}", "generado\t" . now()->toIso8601String()];

        foreach ($tablas as $tabla) {
            if (! Schema::hasTable($tabla)) {
                $notas[] = "tabla_inexistente\t{$tabla}";
                continue;
            }
            if (! $this->aceptaLaTabla($tabla)) {
                $notas[] = "sin_reg_medico_en_el_api\t{$tabla}";
                continue;
            }

            [$columnas, $excluidas] = $this->columnasAExportar($tabla);
            if ($excluidas !== []) {
                $omitidas[$tabla] = $excluidas;
                foreach ($excluidas as $columna) {
                    $manifiesto[] = "omitida\t{$tabla}\t{$columna}";
                }
            }
            if ($columnas === []) {
                $notas[] = "sin_columnas\t{$tabla}";
                continue;
            }

            $rutaRelativa = 'datos/' . $tabla . '.tsv';
            $cantidad = $this->escribirTabla(
                $tabla,
                $regMedico,
                $carpeta . DIRECTORY_SEPARATOR . 'datos' . DIRECTORY_SEPARATOR . $tabla . '.tsv',
                $columnas
            );

            if ($cantidad === 0) {
                @unlink($carpeta . DIRECTORY_SEPARATOR . 'datos' . DIRECTORY_SEPARATOR . $tabla . '.tsv');
                $vacias[] = $tabla;
                $manifiesto[] = "vacia\t{$tabla}";
                $filas[$tabla] = 0;
            } else {
                $archivos[$tabla] = $rutaRelativa;
                $filas[$tabla] = $cantidad;
                $manifiesto[] = "tabla\t{$tabla}\t{$rutaRelativa}\t" . implode(',', $columnas) . "\t{$cantidad}";
            }

            if ($aviso) {
                $aviso($tabla, $cantidad);
            }
        }

        $total = array_sum($filas);
        // Un resumen arriba del manifiesto, para que se pueda leer sin sumar a mano.
        array_splice($manifiesto, 3, 0, [
            'tablas_con_datos' . "\t" . count($archivos),
            'tablas_vacias' . "\t" . count($vacias),
            'filas' . "\t" . $total,
        ]);

        $this->escribir($carpeta . DIRECTORY_SEPARATOR . 'manifiesto.txt', implode("\r\n", $manifiesto) . "\r\n");

        return [
            'carpeta'     => $carpeta,
            'archivos'    => $archivos,
            'filas'       => $filas,
            'total_filas' => $total,
            'vacias'      => $vacias,
            'omitidas'    => $omitidas,
            'notas'       => $notas,
            'carga'       => $this->filasDeLaCarga($regMedico),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Qué se exporta                                                      */
    /* ------------------------------------------------------------------ */

    /** ¿La tabla guarda las filas del médico con `reg_medico`? `pacientes` es el caso especial. */
    private function aceptaLaTabla(string $tabla): bool
    {
        if ($tabla === 'pacientes') {
            return true;
        }

        return in_array('reg_medico', $this->tablas->columnasDe($tabla), true);
    }

    /**
     * Columnas del API que viajan al escritorio, y las que se dejan afuera a propósito.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function columnasAExportar(string $tabla): array
    {
        $excluidasConfig = config("sync_legado.columnas_excluidas.{$tabla}", []);
        $fuera = array_merge(
            self::COLUMNAS_DEL_API,
            $excluidasConfig,
            self::COLUMNAS_ESPECIALES[$tabla] ?? []
        );

        $columnas = array_values(array_diff($this->tablas->columnasDe($tabla), $fuera));

        // En `pacientes` el número de historia es la clave del legado: viaja siempre, aunque la
        // columna no exista en el esquema del API (el aplicador la intersecta con su base local).
        if ($tabla === 'pacientes' && ! in_array('numhistoria', $columnas, true)) {
            $columnas[] = 'numhistoria';
        }

        // Lo que se omite a propósito se informa: el operador tiene que saber qué no va a volver.
        $informadas = array_values(array_unique(array_merge($excluidasConfig, self::COLUMNAS_ESPECIALES[$tabla] ?? [])));

        return [$columnas, $informadas];
    }

    /* ------------------------------------------------------------------ */
    /* Lectura y escritura por tabla                                       */
    /* ------------------------------------------------------------------ */

    /** @param string[] $columnas */
    private function escribirTabla(string $tabla, string $regMedico, string $ruta, array $columnas): int
    {
        $salida = @fopen($ruta, 'wb');
        if ($salida === false) {
            throw new RuntimeException("No se pudo escribir {$ruta}.");
        }

        $filas = 0;
        try {
            foreach ($this->filasDe($tabla, $regMedico) as $datos) {
                $valores = [];
                foreach ($columnas as $columna) {
                    $valores[] = $this->valor($datos[$columna] ?? null);
                }
                fwrite($salida, implode("\t", $valores) . "\r\n");
                $filas++;
            }
        } finally {
            fclose($salida);
        }

        return $filas;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function filasDe(string $tabla, string $regMedico): \Generator
    {
        if ($tabla === 'pacientes') {
            yield from $this->filasDePacientes($regMedico);

            return;
        }

        $consulta = DB::table($tabla)->where('reg_medico', $regMedico);
        // `lazy()` pagina por dentro: las tablas grandes (consultas, recipe_detalle) no entran en
        // memoria de una sola vez. El orden tiene que ser estable entre páginas.
        $orden = Schema::hasColumn($tabla, 'id') ? 'id' : $this->tablas->columnasDe($tabla)[0];

        foreach ($consulta->orderBy($orden)->lazy(self::FILAS_POR_LECTURA) as $fila) {
            $datos = (array) $fila;

            if ($tabla === 'cola') {
                // El escritorio usa otra semántica en `estado` (factura) y en `atendido` (movida).
                $datos = AtencionCita::aEscritorio(EstadoCita::aEscritorio($datos));
            }

            yield $datos;
        }
    }

    /**
     * Pacientes del médico como filas de la tabla `pacientes` del legado: el número de historia es
     * el del **escritorio** (`historias.clave_escritorio`) y los datos salen del paciente del API.
     * Un paciente del app sin número de escritorio no tiene lugar en esa tabla y se omite.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function filasDePacientes(string $regMedico): \Generator
    {
        $historias = DB::table('historias')
            ->where('reg_medico', $regMedico)
            ->whereNotNull('paciente_id')
            ->orderBy('id')
            ->get(['paciente_id', 'numhistoria', 'clave_escritorio']);

        // Las mismas columnas que se anuncian en el manifiesto, sin el número de historia (que sale
        // de la historia del escritorio, no del paciente del API).
        $columnasPaciente = array_values(array_diff($this->columnasAExportar('pacientes')[0], ['numhistoria']));

        foreach ($historias->chunk(self::FILAS_POR_LECTURA) as $tramo) {
            $pacientes = DB::table('pacientes')
                ->whereIn('id', $tramo->pluck('paciente_id')->all())
                ->get()
                ->keyBy('id');

            foreach ($tramo as $historia) {
                $paciente = $pacientes[$historia->paciente_id] ?? null;
                if (! $paciente) {
                    continue;
                }

                $datos = [];
                foreach ($columnasPaciente as $columna) {
                    $datos[$columna] = $paciente->{$columna} ?? null;
                }
                $datos['numhistoria'] = $this->numeroDeEscritorio($historia);

                yield $datos;
            }
        }
    }

    /** El número de historia del escritorio: su clave, y si no la tiene, el número que le dio el API. */
    private function numeroDeEscritorio(object $historia): ?string
    {
        $clave = $historia->clave_escritorio ?? null;
        if ($clave !== null && trim((string) $clave) !== '') {
            return trim((string) $clave);
        }

        return $historia->numhistoria !== null && trim((string) $historia->numhistoria) !== ''
            ? trim((string) $historia->numhistoria)
            : null;
    }

    /* ------------------------------------------------------------------ */
    /* Valores                                                             */
    /* ------------------------------------------------------------------ */

    private function valor(mixed $valor): string
    {
        if ($valor === null) {
            return '\N';
        }
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            return $this->numero($valor);
        }
        if ($valor instanceof DateTimeInterface) {
            return $this->escapar($valor->format($valor->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s'));
        }
        if (is_resource($valor) || is_object($valor)) {
            // No debería pasar en estas tablas (no hay columnas binarias en el legado): se informa
            // como NULL en vez de romper la restauración entera.
            return '\N';
        }

        return $this->escapar($this->sinHoraDeMedianoche((string) $valor));
    }

    /**
     * `2001-06-24 00:00:00` → `2001-06-24`: MySQL devuelve así las columnas DATE (el legado las
     * declara DATE) y SQL Anywhere las toma igual, pero el valor limpio evita conversiones raras.
     * Solo se toca la medianoche exacta: una hora real no se pierde.
     */
    private function sinHoraDeMedianoche(string $texto): string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2}) 00:00:00(\.0+)?$/', $texto, $coincide) === 1
            ? $coincide[1]
            : $texto;
    }

    /** Número sin ceros de relleno: `4294.000000000000000` (como lo exporta el legado) → `4294`. */
    private function numero(float $valor): string
    {
        $texto = rtrim(rtrim(number_format($valor, 10, '.', ''), '0'), '.');

        return $texto === '' || $texto === '-' ? '0' : $texto;
    }

    private function escapar(string $texto): string
    {
        return str_replace(['\\', "\t", "\r", "\n"], ['\\\\', '\\t', '\\r', '\\n'], $texto);
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    /** Filas que el API dice haber recibido de este médico en su carga inicial (para contrastar). */
    private function filasDeLaCarga(string $regMedico): array
    {
        $carga = SyncCarga::where('reg_medico', $regMedico)->orderByDesc('id')->first();
        if (! $carga) {
            return [];
        }

        return SyncCargaTabla::where('sync_carga_id', $carga->id)
            ->pluck('filas_recibidas', 'tabla')
            ->map(fn ($filas) => (int) $filas)
            ->all();
    }

    private function asegurarCarpeta(string $carpeta): void
    {
        if (! is_dir($carpeta) && ! mkdir($carpeta, 0775, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear la carpeta {$carpeta}.");
        }
    }

    private function escribir(string $ruta, string $contenido): void
    {
        if (file_put_contents($ruta, $contenido) === false) {
            throw new RuntimeException("No se pudo escribir {$ruta}.");
        }
    }

    private function nombreSeguro(string $regMedico): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $regMedico) ?: 'medico';
    }
}
