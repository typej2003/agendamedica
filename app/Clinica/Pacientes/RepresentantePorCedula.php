<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Facades\DB;

/**
 * **Un representante puede tener varios hijos** (2026-10-19): el mismo adulto responde por más de un niño del
 * consultorio, y la cédula es lo que lo identifica.
 *
 * Hasta hoy las dos pantallas de pediatría lo trataban como si fuera de un solo niño: la ficha mostraba «Hermanos» sólo por
 * `paciente_chamo.familia`, y el alta no pedía representante (se cargaba después, en la ficha). El problema es que el mismo
 * adulto puede estar anotado en **dos familias distintas** del escritorio —hermanos de padre y madre, o niños que llegaron en
 * momentos distintos— y entonces no había forma de saber que era el mismo adulto. La cédula sí lo dice.
 *
 * **Las dos fuentes de la cédula del representante** (las dos son del legado y se leen, no se reestructuran):
 *
 *  1. `representante` —la tabla compartida, una fila por `numhistoria`— con sus columnas nullable `cedula`, `telefono` y
 *     `email`. Es lo que carga **la web** (WEB-3.4) y por eso manda: si hay una fila con esa cédula, sus datos son los que se
 *     muestran y se proponen.
 *  2. El **escritorio de pediatría**: `paciente_chamo.representante` (la cédula anotada en el niño, `char(10)`) y
 *     `familia_bebe.ced_representante` (la cédula de la familia). De la familia salen además el nombre —`status` dice qué padre
 *     es el representante: `'F'` la madre (`nom_madre`) y `'M'` el padre (`nom_padre`)—, el teléfono (`celu_madre` / `cel_padre`
 *     con respaldo `telefono`), el correo y la dirección.
 *
 * **Los hijos** son las historias de las tres vías: las que tienen un `representante` con esa cédula, las que el escritorio
 * marcó con esa cédula en `paciente_chamo.representante` y las de la **misma familia** (`paciente_chamo.familia`). Se resuelven
 * a pacientes del consultorio por `medico_pacientes` (`reg_medico` + `numhistoria`), se deduplican por `paciente_id` y se
 * ordenan por apellido y nombre. Un niño del escritorio sin ficha en la web no aparece: no hay a qué enlazarlo.
 *
 * **Todo acotado al `reg_medico` del contexto**: la cédula `12345678` de un consultorio no tiene nada que ver con la de otro.
 *
 * No sabe de HTTP ni de HTML: lo que se pinta lo arman los ViewModels (`RepresentanteDeAltaVista`, `RepresentanteDePacienteVista`).
 */
final class RepresentantePorCedula
{
    /** Las columnas de `representante` que la pantalla usa. */
    private const COLUMNAS_DEL_REPRESENTANTE = [
        'id', 'numhistoria', 'nombre', 'codeparentesco', 'cedula', 'telefono', 'email', 'direccion',
    ];

    /** Las columnas de `familia_bebe` con las que se arma un representante que sólo está en el escritorio. */
    private const COLUMNAS_DE_LA_FAMILIA = [
        'familia', 'nom_madre', 'nom_padre', 'celu_madre', 'cel_padre', 'telefono', 'email', 'direccion', 'status',
    ];

    /**
     * El parentesco que se propone según `familia_bebe.status`: la madre (`'F'`) es `M`=Madre y el padre (`'M'`) es `P`=Padre,
     * que son los códigos del catálogo `parentesco` del seeder. Un `status` que no diga quién es el representante no propone
     * parentesco: no se inventa.
     */
    private const PARENTESCO_POR_STATUS = ['F' => 'M', 'M' => 'P'];

    /**
     * El representante de una cédula, con sus datos y los pacientes de los que ya es representante en este consultorio.
     *
     * Devuelve `null` cuando la cédula no vino, no es válida o **no está en ninguna de las fuentes**: es lo que el buscador del
     * alta traduce a `encontrado: false`.
     *
     * @return array{nombre:?string, codeparentesco:?string, telefono:?string, email:?string, direccion:?string, familia:?int, hijos:array<int,array{paciente_id:int, nombre:string, historia:?string}>}|null
     */
    public function buscar(string $regMedico, ?string $cedula): ?array
    {
        $cedula = $this->cedulaValida($cedula);

        if ($cedula === null) {
            return null;
        }

        // 1.º la web (el más reciente de esa cédula); 2.º la familia del escritorio.
        $representante = DB::table('representante')
            ->where('reg_medico', $regMedico)
            ->where('cedula', $cedula)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(self::COLUMNAS_DEL_REPRESENTANTE);

        $familia = DB::table('familia_bebe')
            ->where('reg_medico', $regMedico)
            ->where('ced_representante', $cedula)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(self::COLUMNAS_DE_LA_FAMILIA);

        // Las historias de la cédula, primero sin familia: el número de familia sale de ellas (o de la familia encontrada).
        $historias = $this->historiasDeLaCedula($regMedico, $cedula);

        $numeroDeFamilia = $familia !== null
            ? (int) $familia->familia
            : $this->primeraFamilia($regMedico, $historias);

        if ($numeroDeFamilia !== null) {
            $historias = array_merge($historias, $this->historiasDeLaFamilia($regMedico, $numeroDeFamilia));
        }

        $hijos = $this->hijos($regMedico, $historias);

        // La cédula no está en ningún lado: no hay representante que ofrecer.
        if ($representante === null && $familia === null && $hijos === []) {
            return null;
        }

        $deLaFamilia = $this->datosDeLaFamilia($familia);

        return [
            // Los datos de `representante` mandan; los de la familia sólo completan lo que falte.
            'nombre'         => $this->primero([$representante->nombre ?? null, $deLaFamilia['nombre']]),
            'codeparentesco' => $this->primero([$representante->codeparentesco ?? null, $deLaFamilia['codeparentesco']]),
            'telefono'       => $this->primero([$representante->telefono ?? null, $deLaFamilia['telefono']]),
            'email'          => $this->primero([$representante->email ?? null, $deLaFamilia['email']]),
            'direccion'      => $this->primero([$representante->direccion ?? null, $deLaFamilia['direccion']]),
            'familia'        => $numeroDeFamilia,
            'hijos'          => $hijos,
        ];
    }

    /**
     * Los **otros** pacientes de los que esa cédula es representante, para la ficha: la misma lista de `buscar()`, sin el
     * paciente que se está mirando.
     *
     * @return array<int,array{paciente_id:int, nombre:string, historia:?string}>
     */
    public function otrosPacientes(string $regMedico, string $cedula, int $exceptoPacienteId): array
    {
        $encontrado = $this->buscar($regMedico, $cedula);

        if ($encontrado === null) {
            return [];
        }

        return array_values(array_filter(
            $encontrado['hijos'],
            fn (array $hijo) => $hijo['paciente_id'] !== $exceptoPacienteId,
        ));
    }

    /** La cédula limpia y en el formato del legado (sólo dígitos, hasta 10), o `null` si no sirve. */
    private function cedulaValida(?string $cedula): ?string
    {
        $texto = CamposDePaciente::texto($cedula);

        return $texto !== null && preg_match('/^[0-9]{1,10}$/', $texto) === 1 ? $texto : null;
    }

    /**
     * Las historias que apuntan a esa cédula: las de `representante` (lo cargado por la web) y las que el escritorio anotó en
     * el propio niño (`paciente_chamo.representante`).
     *
     * @return array<int,int>
     */
    private function historiasDeLaCedula(string $regMedico, string $cedula): array
    {
        $deLaTabla = DB::table('representante')
            ->where('reg_medico', $regMedico)
            ->where('cedula', $cedula)
            ->pluck('numhistoria')
            ->all();

        $delEscritorio = DB::table('paciente_chamo')
            ->where('reg_medico', $regMedico)
            ->where('representante', $cedula)
            ->pluck('historia')
            ->all();

        return $this->limpiarHistorias(array_merge($deLaTabla, $delEscritorio));
    }

    /**
     * La familia de esas historias, si alguna es un niño del escritorio: es la que se usa para enlazar a un hermano nuevo
     * cuando no hay `familia_bebe` con la cédula.
     *
     * @param  array<int,int>  $historias
     */
    private function primeraFamilia(string $regMedico, array $historias): ?int
    {
        if ($historias === []) {
            return null;
        }

        $familia = DB::table('paciente_chamo')
            ->where('reg_medico', $regMedico)
            ->whereIn('historia', $historias)
            ->orderBy('id')
            ->value('familia');

        return $familia === null ? null : (int) $familia;
    }

    /**
     * Los hermanos del escritorio: todos los niños de esa familia.
     *
     * @return array<int,int>
     */
    private function historiasDeLaFamilia(string $regMedico, int $familia): array
    {
        return $this->limpiarHistorias(
            DB::table('paciente_chamo')
                ->where('reg_medico', $regMedico)
                ->where('familia', $familia)
                ->pluck('historia')
                ->all(),
        );
    }

    /**
     * Las historias resueltas a pacientes del consultorio, sin repetir y ordenadas como el listado.
     *
     * @param  array<int,int>  $historias
     * @return array<int,array{paciente_id:int, nombre:string, historia:?string}>
     */
    private function hijos(string $regMedico, array $historias): array
    {
        if ($historias === []) {
            return [];
        }

        $filas = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $regMedico)
            ->whereIn('medico_pacientes.numhistoria', array_map('strval', $historias))
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->get(['pacientes.id', 'pacientes.apellidos', 'pacientes.nombres', 'medico_pacientes.numhistoria']);

        $hijos = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->id;

            // Una historia y su paciente pueden llegar por las dos vías (la tabla y la familia): es el mismo niño.
            if (isset($hijos[$id])) {
                continue;
            }

            $hijos[$id] = [
                'paciente_id' => $id,
                'nombre'      => CamposDePaciente::nombre($fila->apellidos, $fila->nombres),
                'historia'    => CamposDePaciente::numeroDeHistoria($fila->numhistoria),
            ];
        }

        return array_values($hijos);
    }

    /**
     * Los datos del representante que sólo están en el escritorio de pediatría, sacados de su familia.
     *
     * @return array{nombre:?string, codeparentesco:?string, telefono:?string, email:?string, direccion:?string}
     */
    private function datosDeLaFamilia(?object $familia): array
    {
        $vacio = ['nombre' => null, 'codeparentesco' => null, 'telefono' => null, 'email' => null, 'direccion' => null];

        if ($familia === null) {
            return $vacio;
        }

        $status = mb_strtoupper(trim((string) $familia->status));

        return [
            // `status` dice qué padre es el representante; sin eso no se elige uno de los dos nombres.
            'nombre'         => $status === 'F'
                ? CamposDePaciente::texto($familia->nom_madre)
                : ($status === 'M' ? CamposDePaciente::texto($familia->nom_padre) : null),
            'codeparentesco' => self::PARENTESCO_POR_STATUS[$status] ?? null,
            'telefono'       => $this->primero([
                $status === 'M' ? $familia->cel_padre : ($status === 'F' ? $familia->celu_madre : null),
                $familia->telefono,
            ]),
            'email'          => CamposDePaciente::texto($familia->email),
            'direccion'      => CamposDePaciente::texto($familia->direccion),
        ];
    }

    /**
     * Las historias como enteros, sin nulos ni repetidos (el legado guarda números en columnas de texto).
     *
     * @param  array<int,mixed>  $historias
     * @return array<int,int>
     */
    private function limpiarHistorias(array $historias): array
    {
        $numeros = [];

        foreach ($historias as $historia) {
            $numero = CamposDePaciente::historiaNumerica($historia);

            if ($numero !== null) {
                $numeros[$numero] = $numero;
            }
        }

        return array_values($numeros);
    }

    /** El primer valor con algo escrito (los vacíos y los espacios no cuentan). */
    private function primero(array $valores): ?string
    {
        foreach ($valores as $valor) {
            $texto = CamposDePaciente::texto($valor);

            if ($texto !== null) {
                return $texto;
            }
        }

        return null;
    }
}
