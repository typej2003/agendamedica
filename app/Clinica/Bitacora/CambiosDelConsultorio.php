<?php

namespace App\Clinica\Bitacora;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La lectura de la bitácora** (WEB-8.5): lo que cambió en los registros de un médico y quién lo hizo, leído de `sync_changes`.
 *
 * Cuesta pocas consultas por página y no carga la tabla entera (la base real tiene miles de filas por médico):
 *
 *  1. una consulta agrupada y paginada **en el servidor** —las filas de una misma acción (mismo usuario, origen, tabla y registro
 *     dentro del mismo segundo) son **un** grupo, y se piden 51 para saber si hay otra página— sobre el índice
 *     `(reg_medico, occurred_at)`;
 *  2. una segunda con el detalle de esos grupos (qué columnas), acotada a sus registros y a su ventana de tiempo;
 *  3. una por tabla de la página para resolver el paciente (sólo para armar el enlace) y una para los nombres de los usuarios.
 *
 * Todo está acotado a los `reg_medico` del médico: nunca ve un cambio de otro. **El valor de una columna sólo se trae si el
 * diccionario lo declara visible** (monto, hora, fecha y atención de una cita): el SQL ni siquiera lo selecciona del resto, así
 * que el contenido clínico no llega a la memoria de la pantalla.
 *
 * El filtro por paciente es de **mejor esfuerzo**: resuelve la fila de rastro contra su tabla; una fila que ya no existe (una baja)
 * no se puede relacionar con un paciente y no entra, igual que las tablas que no pertenecen a un paciente (catálogos, horarios).
 */
final class CambiosDelConsultorio
{
    public const POR_PAGINA = 50;

    /** Más pacientes que esto en una búsqueda no la acotan: se pide un nombre más completo. */
    public const MAX_PACIENTES = 30;

    /**
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @return array{grupos: array<int,array<string,mixed>>, hay_mas: bool, hay_anterior: bool, sin_pacientes: bool, demasiados_pacientes: bool}
     */
    public function pagina(array $registros, FiltroDeBitacora $filtro): array
    {
        $vacio = ['grupos' => [], 'hay_mas' => false, 'hay_anterior' => $filtro->pagina > 1, 'sin_pacientes' => false, 'demasiados_pacientes' => false];

        if ($registros === []) {
            return $vacio;
        }

        $base = $this->base($registros, $filtro);

        if ($filtro->paciente !== '') {
            $encontrados = $this->pacientesQueCoinciden($registros, $filtro->paciente);

            if ($encontrados['ids'] === []) {
                return array_merge($vacio, ['sin_pacientes' => true]);
            }

            $this->acotarAPacientes($base, $registros, $encontrados);
        }

        $segundo = 'SUBSTR(occurred_at, 1, 19)';

        $filas = (clone $base)
            ->selectRaw("user_id, source, table_name, record_id, {$segundo} as segundo, MAX(occurred_at) as ultimo, MAX(id) as ultimo_id, COUNT(*) as n")
            ->groupBy('user_id', 'source', 'table_name', 'record_id', DB::raw($segundo))
            ->orderByDesc('ultimo')
            ->orderByDesc('ultimo_id')
            ->offset(($filtro->pagina - 1) * self::POR_PAGINA)
            ->limit(self::POR_PAGINA + 1)
            ->get();

        $hayMas = $filas->count() > self::POR_PAGINA;
        $filas = $filas->take(self::POR_PAGINA)->values();

        if ($filas->isEmpty()) {
            return array_merge($vacio, ['demasiados_pacientes' => $filtro->paciente !== '' && ($encontrados['demasiados'] ?? false)]);
        }

        $grupos = $this->conDetalle($filas, $registros);
        $this->resolverPacientes($grupos, $registros);

        return [
            'grupos' => $grupos,
            'hay_mas' => $hayMas,
            'hay_anterior' => $filtro->pagina > 1,
            'sin_pacientes' => false,
            'demasiados_pacientes' => $filtro->paciente !== '' && ($encontrados['demasiados'] ?? false),
        ];
    }

    // ------------------------------------------------------------------------------------------------ Filtros

    /** @param  array<int,string>  $registros */
    private function base(array $registros, FiltroDeBitacora $filtro)
    {
        $consulta = DB::table('sync_changes')
            ->whereIn('reg_medico', $registros)
            ->whereNotIn('table_name', DiccionarioDeLaBitacora::ALIAS_DEL_TELEFONO)
            // La migración de archivos de la PC no es un cambio que hizo una persona (WEB-T.1): no es parte de la bitácora.
            ->where(fn ($origen) => $origen->whereNull('source')->orWhere('source', '!=', 'migracion'));

        if ($desde = $filtro->desdeUtc()) {
            $consulta->where('occurred_at', '>=', $desde->format('Y-m-d H:i:s'));
        }

        if ($hasta = $filtro->hastaUtc()) {
            $consulta->where('occurred_at', '<', $hasta->format('Y-m-d H:i:s'));
        }

        if ($filtro->usuario === FiltroDeBitacora::SIN_USUARIO) {
            $consulta->whereNull('user_id');
        } elseif ($filtro->usuario !== '') {
            $consulta->where('user_id', (int) $filtro->usuario);
        }

        if ($filtro->tipo !== '') {
            $consulta->whereIn('table_name', DiccionarioDeLaBitacora::tablasDelTipo($filtro->tipo));
        }

        return $consulta;
    }

    /**
     * Los pacientes del médico que coinciden con lo escrito (nombre, apellido, cédula o historia). Acotado: con más de
     * `MAX_PACIENTES` se usan los primeros y la pantalla avisa que afine la búsqueda.
     *
     * @param  array<int,string>  $registros
     * @return array{ids: array<int,int>, historias: array<int,int>, demasiados: bool}
     */
    private function pacientesQueCoinciden(array $registros, string $texto): array
    {
        $consulta = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->whereIn('medico_pacientes.reg_medico', $registros);

        BusquedaDePacientes::aplicar($consulta, $texto);

        $filas = $consulta->orderBy('pacientes.id')->limit(self::MAX_PACIENTES + 1)->get(['pacientes.id', 'medico_pacientes.numhistoria']);

        $demasiados = $filas->count() > self::MAX_PACIENTES;
        $filas = $filas->take(self::MAX_PACIENTES);

        return [
            'ids' => $filas->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'historias' => $filas->pluck('numhistoria')->filter(fn ($n) => $n !== null && ctype_digit(trim((string) $n)))
                ->map(fn ($n) => (int) trim((string) $n))->unique()->values()->all(),
            'demasiados' => $demasiados,
        ];
    }

    /**
     * Deja sólo las filas de rastro cuyo registro pertenece a esos pacientes, tabla por tabla (la forma de llegar al paciente
     * la dice el diccionario). Las tablas que no se relacionan con un paciente no entran.
     *
     * @param  array<int,string>  $registros
     * @param  array{ids: array<int,int>, historias: array<int,int>}  $pacientes
     */
    private function acotarAPacientes($consulta, array $registros, array $pacientes): void
    {
        $consulta->where(function ($alguna) use ($registros, $pacientes) {
            foreach (DiccionarioDeLaBitacora::tablasConPaciente() as $tabla) {
                [$como, $columna] = DiccionarioDeLaBitacora::pacientePor($tabla);

                $alguna->orWhere(function ($deLaTabla) use ($tabla, $como, $columna, $registros, $pacientes) {
                    $deLaTabla->where('table_name', $tabla)->whereIn('record_id', function ($sub) use ($tabla, $como, $columna, $registros, $pacientes) {
                        $sub->from($tabla)->select('id');

                        if ($como === 'paciente') {
                            $sub->whereIn('id', $pacientes['ids']);

                            return;
                        }

                        $sub->whereIn('reg_medico', $registros);

                        if ($como === 'historias') {
                            $sub->whereIn('paciente_id', $pacientes['ids']);
                        } elseif ($como === 'cola') {
                            // La cita de un paciente "sin historia" cuelga del paciente, no de un número.
                            $sub->where(fn ($cita) => $cita->whereIn($columna, $pacientes['historias'])->orWhereIn('paciente_sinhistoria_id', $pacientes['ids']));
                        } else {
                            $sub->whereIn($columna, $pacientes['historias']);
                        }
                    });
                });
            }
        });
    }

    // ------------------------------------------------------------------------------------------------ Detalle

    /**
     * @param  Collection<int,object>  $filas  los grupos de la página
     * @param  array<int,string>  $registros
     * @return array<int,array<string,mixed>>
     */
    private function conDetalle(Collection $filas, array $registros): array
    {
        $minimo = $filas->min('segundo');
        $maximo = $filas->max('segundo');

        // Las columnas cuyo valor se puede mostrar: el resto ni se selecciona (el SQL no trae el contenido clínico).
        $visibles = [];
        $enlaces = [];
        foreach (DiccionarioDeLaBitacora::tablasConValorVisible() as $tabla) {
            $columnas = DiccionarioDeLaBitacora::columnasVisiblesDe($tabla);
            $visibles[] = '(table_name = ? AND column_name IN (' . implode(',', array_fill(0, count($columnas), '?')) . '))';
            $enlaces = array_merge($enlaces, [$tabla], $columnas);
        }

        $detalle = DB::table('sync_changes')
            ->selectRaw('id, user_id, source, table_name, record_id, operation, column_name, occurred_at, CASE WHEN ' . implode(' OR ', $visibles) . ' THEN ' . DB::getQueryGrammar()->wrap('value') . ' ELSE NULL END as valor', $enlaces)
            ->whereIn('reg_medico', $registros)
            ->whereIn('table_name', $filas->pluck('table_name')->unique()->all())
            ->whereIn('record_id', $filas->pluck('record_id')->unique()->all())
            ->where('occurred_at', '>=', $minimo)
            ->where('occurred_at', '<', date('Y-m-d H:i:s', strtotime($maximo . ' UTC') + 1))
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($fila) => $this->clave($fila->user_id, $fila->source, $fila->table_name, $fila->record_id, substr((string) $fila->occurred_at, 0, 19)));

        return $filas->map(function ($fila) use ($detalle) {
            $filasDelGrupo = $detalle->get($this->clave($fila->user_id, $fila->source, $fila->table_name, $fila->record_id, $fila->segundo), collect());

            $columnas = [];
            foreach ($filasDelGrupo as $f) {
                if ($f->column_name !== null && $f->column_name !== '') {
                    $columnas[$f->column_name] = $f->valor;
                }
            }

            return [
                'ultimo' => (string) $fila->ultimo,
                'user_id' => $fila->user_id === null ? null : (int) $fila->user_id,
                'origen' => $fila->source,
                'tabla' => (string) $fila->table_name,
                'registro' => (int) $fila->record_id,
                'filas' => (int) $fila->n,
                'operaciones' => $filasDelGrupo->pluck('operation')->unique()->values()->all(),
                'columnas' => $columnas,
                'paciente' => null,
                'consulta' => null,
            ];
        })->all();
    }

    private function clave($usuario, $origen, $tabla, $registro, $segundo): string
    {
        return implode('|', [$usuario ?? '-', $origen ?? '-', $tabla, $registro, $segundo]);
    }

    // ------------------------------------------------------------------------------------------------ Paciente

    /**
     * A cada grupo con una tabla de paciente le pone `paciente` (`id`, `nombre`) y, para una consulta, `consulta` (su id): sólo
     * sirve para el enlace y para decir de quién es el cambio. Una consulta por tabla de la página.
     *
     * @param  array<int,array<string,mixed>>  $grupos
     * @param  array<int,string>  $registros
     */
    private function resolverPacientes(array &$grupos, array $registros): void
    {
        $porTabla = [];
        foreach ($grupos as $i => $grupo) {
            if (DiccionarioDeLaBitacora::pacientePor($grupo['tabla']) !== null) {
                $porTabla[$grupo['tabla']][$grupo['registro']][] = $i;
            }
        }

        $deCadaFila = []; // [indice de grupo => ['historia' => ?int, 'paciente' => ?int]]
        foreach ($porTabla as $tabla => $registrosDeLaTabla) {
            [$como, $columna] = DiccionarioDeLaBitacora::pacientePor($tabla);
            $ids = array_keys($registrosDeLaTabla);

            if ($como === 'paciente') {
                $filas = DB::table('pacientes')->whereIn('id', $ids)->get(['id']);
                foreach ($filas as $f) {
                    foreach ($registrosDeLaTabla[(int) $f->id] as $i) {
                        $deCadaFila[$i] = ['historia' => null, 'paciente' => (int) $f->id];
                    }
                }

                continue;
            }

            $campos = ['id', $columna];
            if ($como === 'cola') {
                $campos[] = 'paciente_sinhistoria_id';
            }

            $filas = DB::table($tabla)->whereIn('id', $ids)->whereIn('reg_medico', $registros)->get($campos);
            foreach ($filas as $f) {
                foreach ($registrosDeLaTabla[(int) $f->id] as $i) {
                    $deCadaFila[$i] = $como === 'historias'
                        ? ['historia' => null, 'paciente' => (int) $f->paciente_id]
                        : ['historia' => $f->{$columna} === null ? null : (int) $f->{$columna}, 'paciente' => $como === 'cola' && $f->paciente_sinhistoria_id ? (int) $f->paciente_sinhistoria_id : null];
                }
            }
        }

        if ($deCadaFila === []) {
            return;
        }

        $historias = collect($deCadaFila)->pluck('historia')->filter(fn ($h) => $h !== null && $h > 0)->unique()->values()->all();
        $idsDeLosPacientes = collect($deCadaFila)->pluck('paciente')->filter()->unique()->values()->all();

        $personas = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->whereIn('medico_pacientes.reg_medico', $registros)
            ->where(function ($donde) use ($historias, $idsDeLosPacientes) {
                if ($historias !== []) {
                    $donde->whereIn(DB::raw('CAST(medico_pacientes.numhistoria AS SIGNED)'), $historias);
                }

                if ($idsDeLosPacientes !== []) {
                    $donde->orWhereIn('pacientes.id', $idsDeLosPacientes);
                }
            })
            ->get(['pacientes.id', 'pacientes.nombres', 'pacientes.apellidos', 'medico_pacientes.numhistoria']);

        $porHistoria = [];
        $porId = [];
        foreach ($personas as $p) {
            $persona = ['id' => (int) $p->id, 'nombre' => trim(trim((string) $p->apellidos) . ', ' . trim((string) $p->nombres), ', ')];
            $porId[(int) $p->id] = $persona;
            if ($p->numhistoria !== null && ctype_digit(trim((string) $p->numhistoria))) {
                $porHistoria[(int) trim((string) $p->numhistoria)] = $persona;
            }
        }

        foreach ($deCadaFila as $i => $ref) {
            $persona = ($ref['paciente'] !== null ? ($porId[$ref['paciente']] ?? null) : null)
                ?? ($ref['historia'] !== null ? ($porHistoria[$ref['historia']] ?? null) : null);

            if ($persona !== null) {
                $grupos[$i]['paciente'] = $persona;
            }

            if ($grupos[$i]['tabla'] === 'consultas') {
                $grupos[$i]['consulta'] = $grupos[$i]['registro'];
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ Usuarios

    /**
     * Quién hizo qué: nombre y rol de cada cuenta.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array{nombre:string, rol:string}>
     */
    public function usuarios(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $salida = [];
        foreach (User::with('roles:id,name')->whereIn('id', $ids)->get(['id', 'name']) as $user) {
            $salida[(int) $user->id] = ['nombre' => (string) $user->name, 'rol' => $this->rol($user->roles->pluck('name')->all())];
        }

        return $salida;
    }

    /**
     * Las cuentas que aparecen en el rastro de estos registros, para el filtro «usuario» (incluye a quien ya no tiene acceso).
     *
     * @param  array<int,string>  $registros
     * @return array<int,array{id:int, nombre:string, rol:string}>
     */
    public function usuariosDelRastro(array $registros): array
    {
        if ($registros === []) {
            return [];
        }

        $ids = DB::table('sync_changes')->whereIn('reg_medico', $registros)->whereNotNull('user_id')->distinct()->pluck('user_id')
            ->map(fn ($id) => (int) $id)->all();

        $usuarios = $this->usuarios($ids);

        return collect($usuarios)
            ->map(fn ($u, $id) => ['id' => (int) $id] + $u)
            ->sortBy(fn ($u) => mb_strtolower($u['nombre']))
            ->values()
            ->all();
    }

    /** @param  array<int,string>  $roles */
    public function rol(array $roles): string
    {
        if (array_intersect($roles, ['Root', 'Administrador']) !== []) {
            return 'administración';
        }

        if (in_array('Medico', $roles, true)) {
            return 'médico';
        }

        if (in_array('Secretaria', $roles, true)) {
            return 'secretaría';
        }

        return 'usuario';
    }
}
