<?php

namespace App\Sync\Escritorio;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Paciente;
use App\Models\SyncCarga;
use App\Models\SyncChange;
use App\Models\SyncTraslado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * La **carga inicial sobre un consultorio que ya tiene datos** (modo mezcla).
 *
 * Regla de negocio (decidida con el usuario, 2026-10-31): **el escritorio es la fuente absoluta**. La web
 * es lo nuevo y se acomoda. En concreto:
 *
 *  - El número de historia del escritorio **no cambia nunca**: ya está impreso en papeles del consultorio.
 *  - El número de consulta del escritorio tampoco: la consulta del escritorio entra con su par
 *    `(historia, nroconsulta)` tal como viene.
 *  - Si el API ya tiene un registro en ese número (lo creó la web o el app antes de la carga), ese registro
 *    **no se borra**: se aparta a un rango reservado y, al cerrar la carga, recibe el número libre
 *    siguiente. Se lleva todo lo que cuelga de él (récipes, exámenes, imágenes, diagnósticos…).
 *
 * Por qué un rango reservado y no `max + 1` en el momento del choque: el escritorio puede traer 200
 * consultas numeradas 1..200 y la del API está en la 1. Con `max + 1` la del API se movería a la 2, y la
 * siguiente lote la volvería a mover: 200 veces. Con un rango reservado (base + id) se aparta una sola vez
 * y la pasada final le da el número definitivo cuando ya entró todo el escritorio.
 *
 * Por qué **renumerar y no copiar-y-borrar**: la fila nunca deja de existir, conserva su id y sus hijos se
 * mueven con ella en la misma transacción. Si la pasada final falla, todo sigue vivo en el rango reservado
 * y se puede reintentar; con un borrado quedaría colgada de una tabla de backup.
 *
 * Por qué la detección es explícita y no "que reviente la base": `consultas` **no tiene un índice único
 * por `(numhistoria, nroconsulta)`** —sólo lo tiene `historias` por número y todas por `clave_escritorio`—,
 * así que un par duplicado no da error: se cuela en silencio y las tablas hijas leen la consulta
 * equivocada. Ver la trampa de `TRAMPAS.md`.
 *
 * Los catálogos (tablas que no cuelgan de un paciente) se **reemplazan**: el escritorio trae los suyos. No
 * hay número que renumerar, así que lo reemplazado queda en `sync_traslados` con su copia y `a_revisar`.
 */
final class Traslados
{
    public const MODO_LIMPIA = SyncCarga::MODO_LIMPIA;
    public const MODO_MEZCLA = SyncCarga::MODO_MEZCLA;

    /** Qué se movió: una historia, una consulta o una tabla entera (catálogo). */
    public const HISTORIA = 'historia';
    public const CONSULTA = 'consulta';
    public const TABLA = 'tabla';

    /**
     * Tablas que no se barren nunca: no son datos del consultorio (usuarios, sync, sistema). El barrido
     * además sólo mira tablas con `reg_medico`, así que esto es la segunda red.
     */
    private const NO_BARRER = [
        'users', 'medicos', 'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens', 'sync_cargas', 'sync_carga_tablas',
        'sync_changes', 'sync_credentials', 'sync_traslados',
    ];

    /**
     * Tablas del esquema que guardan un número de historia y/o de consulta, con la columna de cada uno.
     *
     * @var array<string, array{historia: string, consulta: ?string}>|null
     */
    private ?array $numeros = null;

    public function __construct(private TablasLegado $tablas)
    {
    }

    /** El piso del rango reservado: por encima de cualquier número real del escritorio. */
    public function base(): int
    {
        return (int) config('sync_legado.numero_parqueado_base', 900000000);
    }

    /**
     * ¿La tabla cuelga de un paciente (tiene columna de número de historia)?
     *
     * Es la frontera entre lo clínico —que se suma y, si choca, se mueve— y el catálogo del consultorio,
     * que el escritorio reemplaza entero.
     */
    public function esClinica(string $tabla): bool
    {
        return isset($this->numeros()[$tabla]);
    }

    /* ------------------------------------------------------------------ */
    /* Pacientes: el número de historia del escritorio manda               */
    /* ------------------------------------------------------------------ */

    /**
     * Deja libre el número de historia que trae la fila del escritorio y devuelve el número a usar (siempre
     * el del escritorio). Si el número lo tiene **otra** historia, la aparta con todo su árbol; si el mismo
     * paciente ya existe en el API con otro número (lo creó la web), esa historia **adopta** el número del
     * escritorio y se lleva sus consultas.
     *
     * Corre dentro de la transacción del lote: si el lote se rechaza, no queda nada a medias.
     *
     * @param  array  $fila  fila ya traducida a columnas del API (`TablasLegado::traducirPaciente`)
     * @return string el número de historia del API a escribir (el del escritorio)
     */
    public function prepararPaciente(SyncCarga $carga, array $fila, $numHistoria): string
    {
        $h = (string) ((int) $numHistoria);
        $reg = $carga->reg_medico;

        // 1) La historia de este consultorio que YA representa ese número del escritorio.
        $porClave = Historia::where('reg_medico', $reg)
            ->where('clave_escritorio', TablasLegado::claveHistoria($h))
            ->first();

        // 2) Si no, el mismo paciente por cédula (la identidad compartida entre médicos).
        $paciente = $porClave ? null : $this->pacientePorCedula($fila['cedula'] ?? null);

        // 3) La historia de ese paciente en este consultorio (la que creó la web, con otro número).
        $destino = $porClave;
        if (! $destino && $paciente) {
            $destino = Historia::where('reg_medico', $reg)->where('paciente_id', $paciente->id)->first();
        }

        // 4) El número del escritorio es absoluto: si lo ocupa otra historia, se aparta.
        $ocupante = Historia::where('reg_medico', $reg)->where('numhistoria', $h)->first();
        if ($ocupante && (! $destino || $ocupante->id !== $destino->id)) {
            $this->apartarHistoria($carga, $ocupante);
        }

        // 5) El mismo paciente con otro número: la historia adopta el del escritorio y se lleva su árbol.
        if ($destino && (string) $destino->numhistoria !== $h) {
            $this->moverYAnotarHistoria($carga, $destino, $h, 'paciente_adoptado');
        }

        return $h;
    }

    private function pacientePorCedula($cedula): ?Paciente
    {
        $cedula = trim((string) $cedula);
        if ($cedula === '' || $cedula === '0') {
            return null;
        }

        return Paciente::where('cedula', $cedula)->first();
    }

    /* ------------------------------------------------------------------ */
    /* Consultas: el par (historia, nroconsulta) del escritorio manda      */
    /* ------------------------------------------------------------------ */

    /**
     * Antes de insertar un lote de `consultas`: aparta la consulta del API que ocupe un par del escritorio.
     *
     * Si la consulta ya es la del escritorio (su `clave_escritorio` coincide) no se toca: es un reintento
     * de un lote que ya se aplicó.
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    public function prepararConsultas(SyncCarga $carga, array $filas): void
    {
        $reg = $carga->reg_medico;
        $vistos = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $fila = $this->tablas->traducirColumnas('consultas', $this->tablas->normalizarClaves($fila));
            $h = $fila['numhistoria'] ?? null;
            $c = $fila['nroconsulta'] ?? null;
            if ($h === null || $c === null) {
                continue;
            }

            $clave = TablasLegado::claveConsulta($h, $c);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;

            $delEscritorio = Consulta::where('reg_medico', $reg)->where('clave_escritorio', $clave)->exists();
            if ($delEscritorio) {
                continue;
            }

            // Sin la red de un índice único (ver el docblock de la clase), la detección es explícita.
            $ocupante = Consulta::where('reg_medico', $reg)
                ->where('numhistoria', (int) $h)
                ->where('nroconsulta', (int) $c)
                ->first();
            if ($ocupante) {
                $this->apartarConsulta($carga, $ocupante);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Catálogos: el escritorio trae los suyos y reemplaza                 */
    /* ------------------------------------------------------------------ */

    /**
     * En el primer lote de una tabla de catálogo (`desde = 0`), aparta lo que el API tenía de esa tabla para
     * el médico y lo deja en `sync_traslados` con su copia. Después el lote del escritorio entra y la tabla
     * queda como la del escritorio.
     *
     * Lo que creó el app o la web se **conserva**: las filas anotadas como `created` en `sync_changes` no
     * se pisan, igual que en el reemplazo de una tabla sin clave del sync incremental.
     */
    public function reemplazarCatalogo(SyncCarga $carga, string $tabla, int $desde): void
    {
        // La configuración del médico se actualiza por clave (`guardarEvolucion`), no se reemplaza.
        if ($desde !== 0 || $tabla === 'evolucion') {
            return;
        }
        if (! Schema::hasTable($tabla) || ! $this->tablas->tieneColumna($tabla, 'reg_medico')) {
            return;
        }
        if ($this->esClinica($tabla)) {
            return;   // cuelga de un paciente: se suma, no se pisa
        }

        $protegidas = $this->idsCreadosPorElApp($carga, $tabla);
        $filas = DB::table($tabla)->where('reg_medico', $carga->reg_medico)->orderBy('id')->get()
            ->reject(fn ($f) => in_array((int) $f->id, $protegidas, true))
            ->values();

        if ($filas->isEmpty()) {
            return;
        }

        $this->anotar(
            $carga, $tabla, null, self::TABLA, 'catalogo_reemplazado',
            null, null, null,
            $filas->map(fn ($f) => (array) $f)->all(),
            SyncTraslado::A_REVISAR
        );

        DB::table($tabla)->whereIn('id', $filas->pluck('id')->all())->delete();

        Log::info('Carga inicial: se reemplazó un catálogo del médico con el del escritorio', [
            'reg_medico' => $carga->reg_medico,
            'tabla'      => $tabla,
            'filas'      => $filas->count(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Pasada final: cada trasladado recibe su número                      */
    /* ------------------------------------------------------------------ */

    /**
     * Resuelve los trasladados que quedaron en el rango reservado: les da el número libre siguiente y mueve
     * su árbol. Idempotente: lo que ya está `reacomodado` no se toca, y una fila que ya no existe queda
     * `a_revisar` (no se inventa nada).
     *
     * Corre al final de `finalizar`, cuando ya entró **todo** el escritorio: recién ahí `max + 1` es seguro.
     *
     * @return int cuántos trasladados se procesaron
     */
    public function reacomodar(SyncCarga $carga): int
    {
        self::comprobarBase($this->base());

        $pendientes = $this->pendientes($carga);

        foreach ($pendientes as $traslado) {
            match ($traslado->nivel) {
                self::HISTORIA => $this->reacomodarHistoria($carga, $traslado),
                self::CONSULTA => $this->reacomodarConsulta($carga, $traslado),
                default        => $this->marcar($traslado, SyncTraslado::A_REVISAR),
            };
        }

        return $pendientes->count();
    }

    /** ¿Queda algo que no tiene su número final? */
    public function hayPendientes(SyncCarga $carga): bool
    {
        return SyncTraslado::where('sync_carga_id', $carga->id)
            ->where('estado', SyncTraslado::PENDIENTE)->exists();
    }

    private function pendientes(SyncCarga $carga)
    {
        // Las historias primero: una consulta pendiente puede colgar de una historia que también se mueve.
        return SyncTraslado::where('sync_carga_id', $carga->id)
            ->where('estado', SyncTraslado::PENDIENTE)
            ->orderByRaw("CASE nivel WHEN '" . self::HISTORIA . "' THEN 0 WHEN '" . self::CONSULTA . "' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get();
    }

    private function reacomodarHistoria(SyncCarga $carga, SyncTraslado $traslado): void
    {
        $historia = Historia::find($traslado->registro_id);
        if (! $historia) {
            $this->marcar($traslado, SyncTraslado::A_REVISAR, 'la historia ya no existe');

            return;
        }

        $final = $this->siguienteNumeroHistoria($carga);
        $this->moverHistoria($carga->reg_medico, (string) $historia->numhistoria, $final);

        $this->marcar($traslado, SyncTraslado::REACOMODADO, null, $final);
    }

    private function reacomodarConsulta(SyncCarga $carga, SyncTraslado $traslado): void
    {
        $consulta = Consulta::find($traslado->registro_id);
        if (! $consulta) {
            $this->marcar($traslado, SyncTraslado::A_REVISAR, 'la consulta ya no existe');

            return;
        }

        $final = $this->siguienteNumeroConsulta($carga, (int) $consulta->numhistoria);
        $this->moverConsulta(
            $carga->reg_medico,
            (int) $consulta->numhistoria, (int) $consulta->nroconsulta,
            (int) $consulta->numhistoria, $final
        );

        $this->marcar($traslado, SyncTraslado::REACOMODADO, null, (string) $final);
    }

    /**
     * El siguiente número de historia libre del consultorio, sin contar los que están apartados.
     *
     * El máximo se calcula en PHP y no con un `CAST` en SQL a propósito: `numhistoria` es texto y el
     * `CAST(... AS UNSIGNED)` no se escribe igual en MySQL y en SQLite (la suite corre en SQLite). Son
     * decenas de miles de filas como mucho, y sólo se paga cuando hubo algo que mover.
     */
    private function siguienteNumeroHistoria(SyncCarga $carga): string
    {
        $apartadas = SyncTraslado::where('sync_carga_id', $carga->id)
            ->where('nivel', self::HISTORIA)
            ->where('estado', SyncTraslado::PENDIENTE)
            ->pluck('registro_id')->all();

        $max = DB::table('historias')
            ->where('reg_medico', $carga->reg_medico)
            ->when($apartadas !== [], fn ($q) => $q->whereNotIn('id', $apartadas))
            ->pluck('numhistoria')
            ->map(fn ($n) => (int) $n)
            ->max();

        return (string) (((int) $max) + 1);
    }

    /** El siguiente número de consulta libre de esa historia, sin contar las que están apartadas. */
    private function siguienteNumeroConsulta(SyncCarga $carga, int $numHistoria): int
    {
        $apartadas = SyncTraslado::where('sync_carga_id', $carga->id)
            ->where('nivel', self::CONSULTA)
            ->where('estado', SyncTraslado::PENDIENTE)
            ->pluck('registro_id')->all();

        $max = DB::table('consultas')
            ->where('reg_medico', $carga->reg_medico)
            ->where('numhistoria', $numHistoria)
            ->when($apartadas !== [], fn ($q) => $q->whereNotIn('id', $apartadas))
            ->max('nroconsulta');

        return ((int) $max) + 1;
    }

    /* ------------------------------------------------------------------ */
    /* El apartado                                                         */
    /* ------------------------------------------------------------------ */

    /** Aparta una historia (y todo lo que cuelga de ella) al rango reservado. */
    private function apartarHistoria(SyncCarga $carga, Historia $historia): void
    {
        self::comprobarBase($this->base());

        $original = (string) $historia->numhistoria;
        $temporal = (string) ($this->base() + (int) $historia->id);
        $copia = $this->copiaDe('historias', $historia->id);   // antes de mover: la copia es "como estaba"

        $this->moverHistoria($carga->reg_medico, $original, $temporal);

        $this->anotar(
            $carga, 'historias', (int) $historia->id, self::HISTORIA, 'historia_ocupada',
            $original, $temporal, null, $copia
        );
    }

    /** Aparta una consulta (y todo lo que cuelga de ella) al rango reservado. */
    private function apartarConsulta(SyncCarga $carga, Consulta $consulta): void
    {
        self::comprobarBase($this->base());

        $original = (string) $consulta->nroconsulta;
        $temporal = (int) ($this->base() + (int) $consulta->id);
        $copia = $this->copiaDe('consultas', $consulta->id);   // antes de mover: la copia es "como estaba"

        $this->moverConsulta(
            $carga->reg_medico,
            (int) $consulta->numhistoria, (int) $consulta->nroconsulta,
            (int) $consulta->numhistoria, $temporal
        );

        $this->anotar(
            $carga, 'consultas', (int) $consulta->id, self::CONSULTA, 'consulta_ocupada',
            $original, (string) $temporal, null, $copia
        );
    }

    /** Renumera una historia que ya existía para que tome el número del escritorio. */
    private function moverYAnotarHistoria(SyncCarga $carga, Historia $historia, string $final, string $motivo): void
    {
        $original = (string) $historia->numhistoria;
        if ($original === $final) {
            return;
        }

        $copia = $this->copiaDe('historias', $historia->id);
        $this->moverHistoria($carga->reg_medico, $original, $final);

        $this->anotar(
            $carga, 'historias', (int) $historia->id, self::HISTORIA, $motivo,
            $original, null, $final, $copia, SyncTraslado::REACOMODADO
        );
    }

    private function copiaDe(string $tabla, $id): array
    {
        $fila = DB::table($tabla)->where('id', $id)->first();

        return $fila ? (array) $fila : [];
    }

    private function anotar(
        SyncCarga $carga,
        string $tabla,
        ?int $registroId,
        string $nivel,
        string $motivo,
        ?string $original,
        ?string $temporal,
        ?string $final,
        $copia,
        string $estado = SyncTraslado::PENDIENTE
    ): SyncTraslado {
        return SyncTraslado::create([
            'reg_medico'     => $carga->reg_medico,
            'sync_carga_id'  => $carga->id,
            'tabla'          => $tabla,
            'registro_id'    => $registroId,
            'nivel'          => $nivel,
            'motivo'         => $motivo,
            'clave_original' => $original,
            'clave_temporal' => $temporal,
            'clave_final'    => $final,
            'copia'          => $copia === null ? null : json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'estado'         => $estado,
        ]);
    }

    private function marcar(SyncTraslado $traslado, string $estado, ?string $nota = null, ?string $final = null): void
    {
        $traslado->estado = $estado;
        if ($final !== null) {
            $traslado->clave_final = $final;
        }
        if ($nota !== null) {
            // La nota no tiene columna propia: va con la copia, que es el registro de qué pasó.
            $traslado->copia = json_encode([
                'nota' => $nota,
                'fila' => $traslado->copiaDecodificada(),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $traslado->save();
    }

    /* ------------------------------------------------------------------ */
    /* El barrido por número                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Renumera una historia en el API: `historias`, el vínculo (`medico_pacientes`) y **toda** tabla que
     * guarde su número. No se lista a mano: se descubre del esquema (ver `numeros()`), así que una tabla
     * nueva del legado entra sola.
     */
    private function moverHistoria(string $regMedico, string $de, string $a): void
    {
        if ($de === $a) {
            return;
        }

        foreach ($this->numeros() as $tabla => $columnas) {
            $this->renumerar($tabla, $regMedico, $columnas['historia'], $de, $a);
        }
    }

    /**
     * Renumera una consulta: las tablas que guardan el par `(historia, consulta)`, incluida `consultas`.
     */
    private function moverConsulta(string $regMedico, int $historia, int $de, int $historiaFinal, int $a): void
    {
        if ($de === $a && $historia === $historiaFinal) {
            return;
        }

        foreach ($this->numeros() as $tabla => $columnas) {
            if ($columnas['consulta'] === null) {
                continue;
            }

            $datos = [$columnas['consulta'] => $a];
            if ($historiaFinal !== $historia) {
                $datos[$columnas['historia']] = $historiaFinal;
            }
            if ($this->tablas->tieneColumna($tabla, 'updated_at')) {
                $datos['updated_at'] = now();
            }

            DB::table($tabla)->where('reg_medico', $regMedico)
                ->where($columnas['historia'], $historia)
                ->where($columnas['consulta'], $de)
                ->update($datos);
        }
    }

    private function renumerar(string $tabla, string $regMedico, string $columna, $de, $a): void
    {
        $datos = [$columna => $a];
        if ($this->tablas->tieneColumna($tabla, 'updated_at')) {
            $datos['updated_at'] = now();
        }

        DB::table($tabla)->where('reg_medico', $regMedico)->where($columna, $de)->update($datos);
    }

    /**
     * Tablas del esquema que guardan un número de historia (y, si corresponde, de consulta).
     *
     * Se recorre el esquema y no una lista escrita a mano a propósito: la lista del sync tiene más de cien
     * tablas del legado y olvidarse una deja filas apuntando a la consulta equivocada, en silencio. La
     * columna sale de `TablasLegado::columnaHistoria()` —la fuente única de esa diferencia— y, si la tabla
     * no está en ese mapa, de la primera de las tres formas conocidas que exista.
     *
     * Sólo entran las tablas con `reg_medico`: sin él no se puede acotar al consultorio y renumerar sería
     * tocar datos de otros médicos.
     *
     * @return array<string, array{historia: string, consulta: ?string}>
     */
    private function numeros(): array
    {
        if ($this->numeros !== null) {
            return $this->numeros;
        }

        $mapa = [];
        foreach (Schema::getTables() as $definicion) {
            $tabla = strtolower((string) (is_array($definicion) ? ($definicion['name'] ?? '') : ($definicion->name ?? '')));
            if ($tabla === '' || in_array($tabla, self::NO_BARRER, true)) {
                continue;
            }

            $columnas = $this->tablas->columnasDe($tabla);
            if (! in_array('reg_medico', $columnas, true)) {
                continue;
            }

            $historia = $this->columnaHistoria($tabla, $columnas);
            if ($historia === null) {
                continue;
            }

            $consulta = null;
            foreach (TablasLegado::COLUMNAS_CONSULTA as $candidata) {
                if (in_array($candidata, $columnas, true)) {
                    $consulta = $candidata;
                    break;
                }
            }

            $mapa[$tabla] = ['historia' => $historia, 'consulta' => $consulta];
        }

        return $this->numeros = $mapa;
    }

    /**
     * @param  string[]  $columnas
     */
    private function columnaHistoria(string $tabla, array $columnas): ?string
    {
        // `TablasLegado::columnaHistoria()` manda: `cola` tiene además una `nrohistoria` vieja que NO lleva
        // el número (trampa 122 de TRAMPAS.md).
        $preferida = TablasLegado::columnaHistoria($tabla);
        if (in_array($preferida, $columnas, true)) {
            return $preferida;
        }

        foreach (TablasLegado::COLUMNAS_HISTORIA as $candidata) {
            if (in_array($candidata, $columnas, true)) {
                return $candidata;
            }
        }

        return null;
    }

    /**
     * Filas de una tabla de catálogo que creó el app o la web y que el reemplazo NO debe pisar.
     *
     * @return int[]
     */
    private function idsCreadosPorElApp(SyncCarga $carga, string $tabla): array
    {
        $nombres = [(string) $tabla];
        foreach ((array) config('sync_legado.sin_clave_con_altas_del_app', []) as $delLegado => $delApp) {
            if ($delLegado === $tabla && $delApp !== $tabla) {
                $nombres[] = (string) $delApp;
            }
        }

        return SyncChange::where('reg_medico', $carga->reg_medico)
            ->whereIn('table_name', $nombres)
            ->where('operation', 'created')
            ->pluck('record_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Comprueba que el rango reservado no choque con nada real. Lo usa la suite.
     *
     * @throws RuntimeException si un número real cae en el rango
     */
    public static function comprobarBase(int $base): void
    {
        if ($base <= 0 || $base >= 2147483647) {
            throw new RuntimeException('El rango reservado de la carga inicial no es válido: ' . $base);
        }
    }
}
