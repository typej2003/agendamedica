<?php

namespace App\Clinica\Pacientes;

use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de los pacientes del **consultorio** (`medico_pacientes` + `pacientes` + `consultas`).
 *
 * Lee de las tablas reales, no de `refresh-data`, que tiene el doble shape que ya documentó el móvil.
 * **Todo** se filtra por los `reg_medico` que se le pasan: un paciente de un consultorio ajeno no se
 * devuelve ni se confirma que exista. No sabe de HTTP ni de HTML; lo que muestra la pantalla lo arman los
 * ViewModels de `Vistas/`.
 *
 * El número de historia vive en `historias` y en `medico_pacientes`, y los dos los escriben juntos todos
 * los caminos de sync (carga inicial, escritorio, alta del app, `AsignarHistoria`). Acá se lee **del
 * pivote**, que desde la Fase 1 del cambio de `reg_medico` es el vínculo **del consultorio** —una fila
 * por `(reg_medico, paciente_id)`—, y se limpia con `CamposDePaciente`: el legado lo guarda como texto y
 * acepta vacíos y espacios. `FiltroDePacientes` es quien dice, en SQL y en un solo lugar, qué filas de
 * ese pivote están "sin historia" (WEB-3.3).
 *
 * **Desde la Fase 2 del cambio de `reg_medico` (2026-10-20) la lectura es multi-consultorio y tiene
 * contexto**, que son dos cosas distintas y las dos importan:
 *
 *  - `$registros` es **lo que el usuario puede ver**: todos los del doctor (el suyo y los centros donde
 *    trabaja) o, en la secretaría, sólo el del centro. El paciente aparece **una vez** aunque tenga
 *    vínculo en dos de esos consultorios.
 *  - `$contextoRegistro` es el consultorio con el que se está trabajando, y de **él** sale el
 *    `numhistoria` que se muestra. Si el paciente no tiene historia en el contexto —aunque la tenga en
 *    otro— la ficha dice "sin historia en este consultorio" y ofrece asignarle una de ese consultorio.
 *
 * El vínculo del contexto entra como `LEFT JOIN` (`vinculo`): la clave única `(reg_medico, paciente_id)`
 * garantiza una sola fila por paciente, así que ni el listado ni la cuenta del paginador se duplican y no
 * hace falta `distinct()`.
 */
class PacientesDelConsultorio
{
    private const COLUMNAS = [
        'pacientes.id',
        'pacientes.nombres',
        'pacientes.apellidos',
        'pacientes.cedula',
        'pacientes.fnacimiento',
        'pacientes.sexo',
        'pacientes.telefono',
        'pacientes.email',
        // Los datos de filiación y contacto que la ficha muestra (WEB-3.2: la ficha tiene que decir con
        // quién se atiende al paciente, no solo su teléfono) y el código del seguro, que la pantalla
        // resuelve contra el catálogo del consultorio.
        'pacientes.direccion',
        'pacientes.profesion',
        'pacientes.ocupacion',
        'pacientes.codesegemp',
        'vinculo.numhistoria',
    ];

    public const POR_PAGINA = 25;

    /**
     * El listado con la búsqueda por nombre, apellido, cédula o n.º de historia, paginado y —desde
     * WEB-3.3— filtrable por "tiene historia / no tiene historia".
     *
     * El filtro mira **el contexto**: "sin historia" es "sin número en este consultorio", que es lo que la
     * ficha muestra y lo que la secretaría necesita para ir a llenarlas. `FiltroDePacientes` es el único
     * lugar donde está escrito qué significa (el `null` **y** el `''` del legado), para que la pantalla no
     * diga "Sin historia" en una fila que su propio filtro no trae.
     *
     * @param  array<int,string>  $registros  los `reg_medico` que puede ver el usuario
     * @param  string  $contextoRegistro  el consultorio del contexto (de él sale el número que se muestra)
     */
    public function listar(array $registros, string $contextoRegistro, string $buscar = '', string $filtro = FiltroDePacientes::TODOS): LengthAwarePaginator
    {
        // Sin `distinct()`: desde la Fase 1 del cambio de `reg_medico` el vínculo es **uno por paciente y
        // consultorio** (`UNIQUE (reg_medico, paciente_id)`, migración del 2026-10-20) y el del contexto
        // entra por `LEFT JOIN`, así que el `join` devuelve una fila por paciente y ni la tabla ni la
        // cuenta del paginador pueden duplicarse.
        return $this->conFiltro($this->deLosConsultorios($registros, $contextoRegistro), $filtro)
            ->when($buscar !== '', fn ($consulta) => BusquedaDePacientes::aplicar($consulta, $buscar, 'vinculo.numhistoria'))
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->select(self::COLUMNAS)
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Cuántos pacientes hay de cada lado —todos, sin historia y con historia—, **con la misma búsqueda
     * aplicada**: el segmentado los usa de etiqueta, y un contador que ignore lo que el usuario escribió
     * en el buscador prometería filas que la tabla no muestra.
     *
     * @param  array<int,string>  $registros
     * @return array{todos:int, sinHistoria:int, conHistoria:int}
     */
    public function conteos(array $registros, string $contextoRegistro, string $buscar = ''): array
    {
        $base = fn () => $this->deLosConsultorios($registros, $contextoRegistro)
            ->when($buscar !== '', fn ($consulta) => BusquedaDePacientes::aplicar($consulta, $buscar, 'vinculo.numhistoria'));

        return [
            'todos'       => $base()->count('pacientes.id'),
            'sinHistoria' => $base()->whereRaw(FiltroDePacientes::sqlSinHistoria('vinculo.numhistoria'))->count('pacientes.id'),
            'conHistoria' => $base()->whereRaw(FiltroDePacientes::sqlConHistoria('vinculo.numhistoria'))->count('pacientes.id'),
        ];
    }

    /**
     * El **vínculo** del paciente con el consultorio del contexto, que es lo que dice de quién es: el
     * `numhistoria` del pivote (que es el que muestra la ficha y el que decide si hay algo que asignar) y
     * el `reg_medico` con el que quedó guardado.
     *
     * Devuelve `null` cuando el paciente no tiene vínculo **en este consultorio**: la pantalla que asigna
     * la historia lo crea (`vincular`) si el paciente es visible en otro de los registros del usuario.
     *
     * Es un `object` y no un `MedicoPaciente` a propósito: el pivote no tiene clave primaria propia y la
     * Action decide con el vínculo, no con el modelo.
     */
    public function vinculo(string $regMedico, int $pacienteId): ?object
    {
        $vinculo = DB::table('medico_pacientes')
            ->where('reg_medico', $regMedico)
            ->where('paciente_id', $pacienteId)
            ->first(['paciente_id', 'numhistoria', 'reg_medico']);

        if ($vinculo !== null) {
            $vinculo->numhistoria = CamposDePaciente::numeroDeHistoria($vinculo->numhistoria);
        }

        return $vinculo;
    }

    /**
     * Deja al paciente **vinculado al consultorio del contexto**, sin número de historia (lo numera
     * `AsignarHistoria`). Es lo que permite asignarle una historia de este consultorio a un paciente que
     * sólo estaba vinculado a otro de los registros del doctor: la historia es del tenant y necesita el
     * vínculo del tenant.
     *
     * Idempotente: si el vínculo ya existe no lo toca (y no pisa su `numhistoria` ni su `medico_id`).
     */
    public function vincular(string $regMedico, int $pacienteId, int $medicoId): MedicoPaciente
    {
        return MedicoPaciente::firstOrCreate(
            ['reg_medico' => $regMedico, 'paciente_id' => $pacienteId],
            ['medico_id' => $medicoId],
        );
    }

    /**
     * La ficha de un paciente **visible** en alguno de esos consultorios, o `null` si no es de ninguno.
     *
     * El `numhistoria` que devuelve es el del **contexto** y ya viene limpio —texto sin espacios, `null`
     * si el vínculo del contexto todavía no tiene número—, para que ninguna pantalla repita el `!== null`
     * que el `''` del legado rompe. Un paciente que tiene historia en otro consultorio del doctor pero no
     * en éste devuelve `numhistoria` nulo y la ficha lo dice.
     *
     * `$registros` puede venir como un solo `reg_medico` (las pantallas clínicas, que trabajan sólo con el
     * contexto) o como la lista completa (la lista global del doctor): en el segundo caso el contexto se
     * pasa aparte.
     */
    public function ficha(array|string $registros, int $pacienteId, ?string $contextoRegistro = null): ?object
    {
        $ficha = $this->deLosConsultorios((array) $registros, $this->contextoDe($registros, $contextoRegistro))
            ->where('pacientes.id', $pacienteId)
            ->select(self::COLUMNAS)
            ->first();

        if ($ficha !== null) {
            $ficha->numhistoria = CamposDePaciente::numeroDeHistoria($ficha->numhistoria);
        }

        return $ficha;
    }

    /**
     * El **paciente completo** para editarlo (la ficha de la pantalla lee un subconjunto de columnas),
     * más su número de historia en el consultorio del contexto. `null` si el paciente no es visible en
     * ninguno de esos consultorios.
     *
     * Se consulta primero la ficha —que es lo que dice si el paciente es visible y cuál es su número en el
     * contexto— y recién después el modelo: así la pantalla de edición nunca toca una ficha ajena, ni
     * siquiera para leerla.
     *
     * @return array{paciente:Paciente, numhistoria:?string}|null
     */
    public function editable(array|string $registros, int $pacienteId, ?string $contextoRegistro = null): ?array
    {
        $ficha = $this->ficha($registros, $pacienteId, $contextoRegistro);

        if ($ficha === null) {
            return null;
        }

        $paciente = Paciente::find($pacienteId);

        if ($paciente === null) {
            return null;
        }

        return [
            'paciente'    => $paciente,
            'numhistoria' => $ficha->numhistoria,
        ];
    }

    /**
     * **Los consultorios del doctor donde el paciente tiene historia**, para el selector "ver otra
     * historia" de la ficha y de la historia. Incluye el registro personal del doctor.
     *
     * Sólo mira los `reg_medico` que se le pasan —los del doctor, o el del centro para la secretaría—, así
     * que nunca puede ofrecer un consultorio ajeno. El nombre de cada uno sale de la fila de `medicos` (el
     * doctor o el centro) y el número, de `historias`, que es la dueña del número (Fase 1).
     *
     * @param  array<int,string>  $registros  los `reg_medico` visibles
     * @return array<int, array{reg_medico:string, nombre:string, numhistoria:?string}>
     */
    public function registrosConHistoria(array $registros, int $pacienteId, string $contextoRegistro): array
    {
        if ($registros === []) {
            return [];
        }

        $historias = DB::table('historias')
            ->whereIn('reg_medico', $registros)
            ->where('paciente_id', $pacienteId)
            ->orderBy('id')
            ->get(['reg_medico', 'numhistoria']);

        if ($historias->isEmpty()) {
            return [];
        }

        $nombres = Medico::whereIn('reg_medico', $historias->pluck('reg_medico')->unique()->values()->all())
            ->orderBy('id')
            ->get(['name', 'lastname', 'reg_medico'])
            ->keyBy('reg_medico');

        $salida = [];

        foreach ($historias as $historia) {
            $regMedico = (string) $historia->reg_medico;
            $medico = $nombres->get($regMedico);

            // El del contexto primero: es donde se está trabajando.
            $clave = $regMedico === $contextoRegistro ? '0' : '1';
            $salida[] = [
                'clave'       => $clave . mb_strtolower($regMedico),
                'reg_medico'  => $regMedico,
                'nombre'      => $medico !== null
                    ? trim(trim((string) $medico->name) . ' ' . trim((string) $medico->lastname))
                    : $regMedico,
                'numhistoria' => CamposDePaciente::numeroDeHistoria($historia->numhistoria),
                'es_contexto' => $regMedico === $contextoRegistro,
            ];
        }

        usort($salida, fn (array $a, array $b) => strcmp($a['clave'], $b['clave']));

        return array_map(function (array $fila) {
            unset($fila['clave']);

            return $fila;
        }, $salida);
    }

    /**
     * Las últimas consultas de la historia **del contexto**; vacío si todavía no tiene n.º de historia.
     *
     * El número llega como texto (así está en el pivote) y acá se vuelve entero, que es como está en
     * `consultas`. Si no es numérico no se busca nada: `(int)` lo volvería `0` y traería las consultas de
     * otro paciente.
     */
    public function consultasDe(string $regMedico, ?string $numhistoria, int $limite = 20): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        return DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->orderByDesc('fecha')
            ->limit($limite)
            ->get(['id', 'fecha', 'nroconsulta', 'enfermedadactual']);
    }

    /**
     * Lo que se escribe en el buscador de "Nueva cita": pocos pacientes para elegir uno. Se exigen dos
     * caracteres porque con uno solo la lista sería el consultorio entero.
     *
     * @param  array<int,string>  $registros  los `reg_medico` visibles (el doctor ve todos los suyos)
     * @return array<int, array{id:int, nombre:string, cedula:?string, numhistoria:?int}>
     */
    public function sugerencias(array|string $registros, string $buscar, int $limite = 10, ?string $contextoRegistro = null): array
    {
        if (mb_strlen($buscar) < 2) {
            return [];
        }

        $filas = BusquedaDePacientes::aplicar(
            $this->deLosConsultorios((array) $registros, $this->contextoDe($registros, $contextoRegistro)),
            $buscar,
            'vinculo.numhistoria',
        )
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->limit($limite)
            ->get([
                'pacientes.id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.cedula',
                'vinculo.numhistoria',
            ]);

        return $filas->map(fn ($fila) => [
            'id'          => (int) $fila->id,
            'nombre'      => CamposDePaciente::nombre($fila->apellidos, $fila->nombres),
            'cedula'      => CamposDePaciente::cedula($fila->cedula),
            'numhistoria' => CamposDePaciente::historiaNumerica($fila->numhistoria),
        ])->values()->all();
    }

    /**
     * La base de la lectura: los pacientes con vínculo en **alguno** de esos consultorios (una fila por
     * paciente) y el vínculo del **contexto** al lado, para leer el número que se muestra.
     *
     * Los visibles entran como **subconsulta derivada** (los `paciente_id` de esos registros, sin
     * repetir) y no como `whereExists`: así el motor arranca por el consultorio —unas pocas miles de
     * filas— y no por `pacientes`. Con `whereExists` el plan elegía recorrer la tabla entera y, con
     * millones de pacientes, la búsqueda por tokens dejaba de servir (medido: 34 → 16 ms por consulta
     * con 200.000 pacientes y un consultorio de 5.000).
     *
     * @param  array<int,string>  $registros
     */
    private function deLosConsultorios(array $registros, string $contextoRegistro)
    {
        if ($registros === []) {
            $registros = [$contextoRegistro];
        }

        $visibles = DB::table('medico_pacientes')
            ->select('paciente_id')
            ->whereIn('reg_medico', $registros)
            // `GROUP BY` y no `DISTINCT`: la clave única `(reg_medico, paciente_id)` ya viene ordenada y el
            // motor lee la subconsulta por índice; con `DISTINCT` materializa con tabla temporal y ordena,
            // y la consulta entera pasa de ~1 ms a ~30 ms (medido con 200.000 pacientes).
            ->groupBy('paciente_id');

        return DB::table('pacientes')
            ->joinSub($visibles, 'visibles', 'visibles.paciente_id', '=', 'pacientes.id')
            ->leftJoin('medico_pacientes as vinculo', function ($union) use ($contextoRegistro) {
                $union->on('vinculo.paciente_id', '=', 'pacientes.id')
                    ->where('vinculo.reg_medico', '=', $contextoRegistro);
            });
    }

    /**
     * El consultorio del que sale el número de historia: el pedido o, cuando la lectura recibe un solo
     * `reg_medico` (las pantallas clínicas), ese mismo.
     *
     * @param  array<int,string>|string  $registros
     */
    private function contextoDe(array|string $registros, ?string $contextoRegistro): string
    {
        if ($contextoRegistro !== null) {
            return $contextoRegistro;
        }

        return is_array($registros) ? (string) (reset($registros) ?: '') : (string) $registros;
    }

    /**
     * El segmentado del listado (WEB-3.3): `sin-historia` deja sólo los que todavía no tienen número y
     * `con-historia` los que sí. La condición sale de `FiltroDePacientes` —no se escribe acá— porque es
     * la misma que usan los contadores de las etiquetas.
     */
    private function conFiltro($consulta, string $filtro)
    {
        return match ($filtro) {
            FiltroDePacientes::SIN_HISTORIA => $consulta->whereRaw(FiltroDePacientes::sqlSinHistoria('vinculo.numhistoria')),
            FiltroDePacientes::CON_HISTORIA => $consulta->whereRaw(FiltroDePacientes::sqlConHistoria('vinculo.numhistoria')),
            default                         => $consulta,
        };
    }
}
