<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Los antecedentes de un paciente** (WEB-4.4, ítem 3 del panel del legado): la lectura y el **mapa del
 * dato** de la sección de antecedentes de la consulta.
 *
 * ## De quién son los antecedentes: del **paciente**, no de la consulta
 *
 * Es lo que dice la fuente y lo que el esquema no deja discutir: `antece_paciente` se llavea por
 * **`numhistoria`** —no tiene ninguna columna de consulta— y la pestaña de antecedentes del escritorio
 * trabaja con el `historia` de la ventana, no con su `consulta`:
 *
 *  - el DataWindow del catálogo pendiente se llena con `Retrieve(historia)`
 *    (`w_consulta_principal.srw:7416-7418` sobre `d_antece_paciente_personal`, `utilitarios/
 *    d_antece_paciente_personal.srd`: `WHERE antece_paciente.numhistoria = :lNroHistoria`);
 *  - el `DELETE` al destildar filtra `numhistoria` + `codeantecedente` (`:7636-7638`);
 *  - el `INSERT` al agregar escribe `numhistoria`, `codeantecedente`, `detalles`, `descripcion` y `tipo`
 *    (`:7754-7764`), **sin** consulta;
 *  - y la lista de alertas del encabezado se lee con
 *    `SELECT descripcion, detalles FROM antece_paciente WHERE numhistoria = :historia AND tipo2 = 'A'`
 *    (`:3072-3081` y `:4540-4549`).
 *
 * O sea: **la sección edita los antecedentes de la historia del paciente**, y lo mismo que se carga
 * atendiendo a alguien se ve en su historia clínica (WEB-3.5) y en su próxima consulta. La pantalla lo
 * dice con todas las letras (`SeccionDeAntecedentesVista::ayuda()`), porque un médico que cree que está
 * cargando "los antecedentes de esta consulta" cargaría distinto.
 *
 * ## Las dos tablas del catálogo
 *
 * `antece_paciente` guarda **la relación** del paciente con un antecedente y `antecedentes` es el
 * **catálogo del médico** de donde sale (`codantecedente` → `descripcion` y `codtipo`). El nombre del
 * **tipo** sale de `tipo_antecedente` (`codetipo` → `descripcion`).
 *
 * Los catálogos del legado traen **repetidos** (284 filas por médico con descripciones como `CANCER` tres
 * veces), así que el buscador ofrece **un antecedente por nombre** (el de código más bajo), como el de
 * motivos de consulta.
 *
 * La resolución del **nombre y el tipo de una fila del paciente** sigue la misma vara que la historia del
 * paciente (`App\Clinica\Historia\HistoriaDelPaciente`): **el catálogo del propio médico manda y el de
 * cualquier otro sirve de respaldo por código** —los datos del legado traen `reg_medico` inconsistente—, y
 * lo que no se puede resolver se muestra **tal cual** (el nombre que la fila guarda, o el código), nunca con
 * un nombre inventado. El **catálogo que se ofrece para agregar**, en cambio, es **sólo el del médico**: ver
 * `filasDelCatalogo()`.
 *
 * ## Los "vacíos" del legado y la casilla de alerta
 *
 * Los campos vacíos del legado son una familia —`NULL`, `''` y el literal `none` que el DataWindow escribió
 * como marcador— y los tres son "sin dato" (ver `EvaluacionFisica::limpiar`; acá se usa
 * `CamposDePaciente::texto`, que ya cubre `NULL` y `''`, más el `none`).
 *
 * La **casilla de Alerta** sí tiene fuente, y es el DataWindow del antecedente del paciente:
 * `d_antecedentes_consulta_bueno.srd` declara la columna como una casilla de verificación con
 * `values="Alerta A/Alerta B"` y `checkbox.on="A" checkbox.off="B"` —encendida escribe **`A`**, apagada
 * **`B`**— y el `retrieve` del encabezado lee la alerta como `tipo2 = 'A'` (`w_consulta_principal.srw:3078`).
 * Por eso `tipo2` **es la columna de la casilla** y no un misterio: `A` = alerta, `B` = no. Lo que sigue
 * siendo cierto es que **`null` no se interpreta** (es como quedaron las filas que inserta el botón
 * *Pasar* del escritorio, que no escribe `tipo2`: `:7754-7764`): un antecedente con `null` se muestra sin
 * marca y **guardarlo sin tocarlo no lo ensucia**.
 *
 * ⚠️ La agrupación sale del tipo: `tipo_antecedente.descripcion` (`d_antece_paciente_agrupado.srd`
 * agrupa y ordena por esa columna), y el orden de los grupos es el de `codetipo` —que es el orden de las
 * siete solapas del escritorio: Per., Fam., H.P., Obst., Gine., A.S., Otros—, no el alfabético.
 */
final class AntecedentesDelPaciente
{
    /**
     * El código de `antece_paciente.tipo2` que el legado lee como la marca de **alerta** del antecedente
     * (`w_consulta_principal.srw:3072-3081`, en negrita y magenta sobre el `st_203` de `:6317-6331`).
     *
     * Es el único valor con significado **afirmado**: sale de la condición del `SELECT` de la fuente y del
     * `checkbox.on` del DataWindow.
     */
    public const TIPO2_ALERTA = 'A';

    /**
     * El valor de `tipo2` cuando la casilla está **destildada**, según el DataWindow
     * (`d_antecedentes_consulta_bueno.srd`: `values="Alerta A/Alerta B"`, `checkbox.off="B"`).
     *
     * La web **no** escribe `B` al destildar: deja `null`, que es el estado de las filas del botón *Pasar*
     * del escritorio y no ensucia con un valor que signifique lo mismo que "sin marcar". La constante
     * existe para **leer** (una fila con `B` de otro escritorio se muestra sin marca, igual que un `null`).
     */
    public const TIPO2_SIN_ALERTA = 'B';

    /** El tope de resultados del buscador del catálogo: es para elegir, no para listar las 284 filas. */
    public const MAX_RESULTADOS = 30;

    /**
     * Los antecedentes **de una historia**, con el nombre del antecedente y el de su tipo ya resueltos.
     *
     * Se ordenan por el **tipo del catálogo** (`codtipo` del antecedente) y después por el código, que es
     * el orden en que el escritorio los muestra dentro de cada solapa
     * (`d_antece_paciente_personal.srd`: `ORDER(NAME="antece_paciente.codeantecedente" ASC=yes)`).
     *
     * `detalles` se devuelve **crudo** (limpiado de vacíos, sin recortar): el recorte para el listado lo
     * hace el ViewModel.
     *
     * @return Collection<int,object{
     *     id:int, codigo:string, nombre:string, detalle:?string, alerta:bool, tipo2:?string,
     *     tipoCodigo:?string, tipoNombre:?string, descripcionCruda:?string, enCatalogo:bool
     * }>
     */
    public function delPaciente(string $regMedico, ?string $numhistoria): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        $catalogo = $this->catalogoDeAntecedentes();
        $tipos = $this->tiposDeAntecedente();

        return DB::table('antece_paciente')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->orderBy('codeantecedente')
            ->get(['id', 'codeantecedente', 'detalles', 'descripcion', 'tipo', 'tipo2'])
            ->map(function ($fila) use ($catalogo, $tipos, $regMedico) {
                $codigo = CamposDePaciente::texto($fila->codeantecedente);
                $delCatalogo = $codigo === null ? null : $this->delCatalogoResuelto($catalogo, $regMedico, $codigo);

                // El `tipo` de la fila manda (es el que el escritorio copió al agregarla y el que el
                // `ORDER BY tipo` de la historia usa); el `codtipo` del catálogo es el respaldo y, si no
                // hay ninguno de los dos, el antecedente cae en "sin clasificar" (el ViewModel lo agrupa).
                $tipoCodigo = $this->limpiar($fila->tipo) ?? $this->limpiar($delCatalogo->codtipo ?? null);

                return (object) [
                    'id' => (int) $fila->id,
                    'codigo' => (string) ($codigo ?? ''),
                    // El nombre del catálogo manda; la copia que la fila guarda es el respaldo del legado
                    // (el `INSERT` del escritorio escribe `descripcion`) y el código, el último recurso.
                    'nombre' => $this->limpiar($delCatalogo->descripcion ?? null)
                        ?? $this->limpiar($fila->descripcion)
                        ?? (string) $codigo,
                    'detalle' => $this->limpiar($fila->detalles),
                    'alerta' => $this->limpiar($fila->tipo2) === self::TIPO2_ALERTA,
                    // Se devuelve crudo porque la Action necesita distinguir "sin marcar" (`null`) de la
                    // `B` que escribió un escritorio: guardar sin tocar la casilla no ensucia la fila.
                    'tipo2' => $this->limpiar($fila->tipo2),
                    'tipoCodigo' => $tipoCodigo,
                    'tipoNombre' => $tipoCodigo === null ? null : ($tipos[$tipoCodigo] ?? null),
                    'descripcionCruda' => $this->limpiar($fila->descripcion),
                    'enCatalogo' => $delCatalogo !== null,
                ];
            })
            ->values();
    }

    /**
     * El **tipo de antecedente** por su código (`tipo_antecedente`), resuelto con el catálogo del médico y
     * con el de cualquier otro como respaldo.
     *
     * `null` si el código no está: el escritorio agrupa por `tipo_antecedente.descripcion` y lo que no se
     * puede resolver se agrupa como "sin clasificar", **sin inventarle un nombre**.
     *
     * @return object{codigo:string, nombre:?string, tipo:?string}|null
     */
    public function tipoDe(string $regMedico, ?string $codigo): ?object
    {
        $codigo = $this->limpiar($codigo);

        if ($codigo === null) {
            return null;
        }

        foreach ($this->tiposDeLaBase() as $fila) {
            $deLaFila = $this->limpiar($fila->codetipo);

            if ($deLaFila !== $codigo) {
                continue;
            }

            if ((string) $fila->reg_medico === $regMedico) {
                return $this->tipo($deLaFila, $fila);
            }
        }

        foreach ($this->tiposDeLaBase() as $fila) {
            if ($this->limpiar($fila->codetipo) === $codigo) {
                return $this->tipo($codigo, $fila);
            }
        }

        return null;
    }

    /**
     * Los siete tipos de la instalación (`tipo_antecedente`), ordenados por `codetipo`: es el orden de las
     * solapas del escritorio (Per., Fam., H.P., Obst., Gine., A.S., Otros) y el de los grupos de la
     * pantalla. El catálogo es de la instalación, no del médico: el escritorio lo sube una vez y ningún
     * médico lo edita.
     *
     * @return array<int,array{codigo:string, nombre:string}>
     */
    public function tipos(): array
    {
        $tipos = [];
        foreach ($this->tiposDeAntecedente() as $codigo => $nombre) {
            $tipos[] = ['codigo' => $codigo, 'nombre' => $nombre];
        }

        return $tipos;
    }

    /**
     * El catálogo de antecedentes del médico, **un antecedente por nombre** (el de código más bajo), como
     * el buscador de motivos: el catálogo del legado trae repetidos y ofrecer dos filas idénticas sería
     * pedirle al médico que adivine.
     *
     * @param  string  $tipo  el `codtipo` por el que filtrar (vacío = todos)
     * @return Collection<int,object{codigo:string, nombre:string, tipoCodigo:?string, tipoNombre:?string}>
     */
    public function catalogo(string $regMedico, string $buscar = '', string $tipo = ''): Collection
    {
        $clave = BusquedaDePacientes::normalizar(trim($buscar));
        $tipo = $this->limpiar($tipo);
        $tipos = $this->tiposDeAntecedente();

        return $this->filasDelCatalogo($regMedico)
            ->filter(function ($fila) use ($clave, $tipo) {
                $tipoCodigo = $this->limpiar($fila->codtipo);

                if ($tipo !== null && $tipoCodigo !== $tipo) {
                    return false;
                }

                return $clave === ''
                    || str_contains(BusquedaDePacientes::normalizar((string) $fila->descripcion), $clave);
            })
            ->groupBy(fn ($fila) => BusquedaDePacientes::normalizar((string) $fila->descripcion))
            ->map(fn (Collection $iguales) => $iguales->sortBy('codantecedente')->first())
            ->sortBy(fn ($fila) => BusquedaDePacientes::normalizar((string) $fila->descripcion))
            ->values()
            ->take(self::MAX_RESULTADOS)
            ->map(function ($fila) use ($tipos) {
                $tipoCodigo = $this->limpiar($fila->codtipo);

                return (object) [
                    'codigo' => (string) $this->limpiar($fila->codantecedente),
                    'nombre' => (string) $this->limpiar($fila->descripcion),
                    'tipoCodigo' => $tipoCodigo,
                    'tipoNombre' => $tipoCodigo === null ? null : ($tipos[$tipoCodigo] ?? null),
                ];
            });
    }

    /**
     * ¿Ese texto **ya es** un antecedente del catálogo del médico? Es lo que decide si el buscador ofrece
     * crearlo (misma vara que los motivos de consulta: "ARTRITIS" con "ARTRITIS REUMATOIDE" en el catálogo
     * **sí** se ofrece, porque son antecedentes distintos).
     */
    public function existeEnElCatalogo(string $regMedico, string $descripcion): bool
    {
        $clave = BusquedaDePacientes::normalizar(trim($descripcion));

        if ($clave === '') {
            return false;
        }

        return $this->filasDelCatalogo($regMedico)
            ->contains(fn ($fila) => BusquedaDePacientes::normalizar((string) $fila->descripcion) === $clave);
    }

    /**
     * El antecedente **del catálogo del médico** por su código, o `null` si no está en él.
     *
     * Es la misma lectura que alimenta el buscador y el alta, así que lo que la pantalla ofrece y lo que el
     * POST acepta no pueden divergir: un código que no está en **su** catálogo no se agrega.
     *
     * @return object{codigo:string, nombre:string, tipoCodigo:?string, tipoNombre:?string}|null
     */
    public function delCatalogo(string $regMedico, ?string $codigo): ?object
    {
        $codigo = $this->limpiar($codigo);

        if ($codigo === null) {
            return null;
        }

        $fila = $this->filasDelCatalogo($regMedico)
            ->first(fn ($fila) => $this->limpiar($fila->codantecedente) === $codigo);

        if ($fila === null) {
            return null;
        }

        $tipoCodigo = $this->limpiar($fila->codtipo);
        $tipos = $this->tiposDeAntecedente();

        return (object) [
            'codigo' => (string) $this->limpiar($fila->codantecedente),
            'nombre' => (string) $this->limpiar($fila->descripcion),
            'tipoCodigo' => $tipoCodigo,
            'tipoNombre' => $tipoCodigo === null ? null : ($tipos[$tipoCodigo] ?? null),
        ];
    }

    /**
     * El siguiente `codantecedente` del catálogo del médico: el correlativo de **cuatro dígitos** del
     * legado, calculado sobre los códigos numéricos (el escritorio lo calcula igual:
     * `w_nuevo_antecedente.srw:124-136` ordena por código, toma el mayor, le suma uno y lo rellena a
     * cuatro). `null` si el catálogo está lleno (`9999`).
     */
    public function siguienteCodigo(string $regMedico): ?string
    {
        $mayor = (int) $this->filasDelCatalogo($regMedico)
            ->pluck('codantecedente')
            ->filter(fn ($codigo) => is_numeric($this->limpiar($codigo)))
            ->map(fn ($codigo) => (int) $this->limpiar($codigo))
            ->max();

        $siguiente = $mayor + 1;

        return $siguiente > 9999 ? null : str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * Las filas del catálogo **del médico**, o una colección vacía si todavía no tiene.
     *
     * ⚠️ Acá **no** hay respaldo por código, a diferencia de las filas del paciente: el catálogo es **del
     * consultorio**, y ofrecer el de otro médico sería ofrecer códigos que su instalación no tiene. Un
     * médico que todavía no sincronizó ve su catálogo vacío (y `siguienteCodigo` arranca en `0001`), con la
     * pantalla diciéndole que cree el primero, en vez de mostrarle 284 antecedentes ajenos. Las **filas del
     * paciente** sí toleran un catálogo que no resuelve: se muestran con el nombre que la fila guarda (o con
     * el código), que es como las lee el escritorio.
     */
    private function filasDelCatalogo(string $regMedico): Collection
    {
        return DB::table('antecedentes')
            ->where('reg_medico', $regMedico)
            ->orderBy('codantecedente')
            ->get(['id', 'codantecedente', 'descripcion', 'codtipo']);
    }

    /**
     * `reg_medico|codigo => fila` con el respaldo `|codigo => fila`: el catálogo del propio médico manda y
     * el de cualquier otro sirve de respaldo (los datos del legado traen `reg_medico` inconsistentes).
     *
     * @return array<string,object>
     */
    private function catalogoDeAntecedentes(): array
    {
        $indice = [];

        foreach (DB::table('antecedentes')->get(['reg_medico', 'codantecedente', 'descripcion', 'codtipo']) as $fila) {
            $codigo = $this->limpiar($fila->codantecedente);

            if ($codigo === null) {
                continue;
            }

            $indice[((string) $fila->reg_medico) . '|' . $codigo] ??= $fila;
            $indice['|' . $codigo] ??= $fila;
        }

        return $indice;
    }

    private function delCatalogoResuelto(array $catalogo, string $regMedico, string $codigo): ?object
    {
        return $catalogo[$regMedico . '|' . $codigo] ?? $catalogo['|' . $codigo] ?? null;
    }

    /** @return Collection<int,object> las filas crudas de `tipo_antecedente` */
    private function tiposDeLaBase(): Collection
    {
        static $filas = null;

        return $filas ??= DB::table('tipo_antecedente')
            ->orderBy('reg_medico')
            ->orderBy('id')
            ->get(['reg_medico', 'codetipo', 'descripcion', 'tipoantecedente']);
    }

    /**
     * `codetipo => descripcion`, el **primero** que aparece: el catálogo dice lo mismo en todas las
     * instalaciones y el orden por `id` es el del escritorio (01 PERSONAL … 07 VARIOS).
     *
     * @return array<string,string>
     */
    private function tiposDeAntecedente(): array
    {
        $tipos = [];

        foreach ($this->tiposDeLaBase() as $fila) {
            $codigo = $this->limpiar($fila->codetipo);
            $nombre = $this->limpiar($fila->descripcion);

            if ($codigo !== null && $nombre !== null) {
                $tipos[$codigo] ??= $nombre;
            }
        }

        return $tipos;
    }

    /** @return object{codigo:string, nombre:?string, tipo:?string} */
    private function tipo(string $codigo, object $fila): object
    {
        return (object) [
            'codigo' => $codigo,
            'nombre' => $this->limpiar($fila->descripcion),
            'tipo' => $this->limpiar($fila->tipoantecedente),
        ];
    }

    /**
     * El `NULL`, el `''` y el literal `none` del DataWindow son el mismo estado: sin dato.
     *
     * `none` no es una invención: es el marcador que el escritorio dejó en 23.971 filas de
     * `examen_fisico_nuevo.total_general` (ver `EvaluacionFisica`), y en los antecedentes aparece como
     * título de DataWindow (`w_consulta_principal.srw:7410`).
     */
    private function limpiar($valor): ?string
    {
        $texto = CamposDePaciente::texto($valor);

        return $texto === null || strcasecmp($texto, 'none') === 0 ? null : $texto;
    }
}
