<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\ItemsDeRecipe;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar un tratamiento del catálogo** (WEB-8.1a): crearlo o corregirlo, con **sus medicamentos de una vez**.
 *
 * Un tratamiento es una plantilla de récipe: un encabezado en `recipe_grupo` (`codigo` y `tratamiento`) y una fila
 * por medicamento en `recipe_grupo_detalle` (`codemedicina`, `descripcion`, `indicaciones`, `cantidad` y `orden`),
 * unidos por `codigo` dentro del `reg_medico`. Al cargarlo en una consulta se **copia** al récipe; no hay referencia viva.
 *
 * ## Lo que dice la fuente del escritorio
 *
 *  - **El código** es el del correlativo de cuatro dígitos, igual que los demás catálogos: la ventana ordena, toma el
 *    mayor, le suma uno y lo rellena (`w_agregar_tratamiento_insitu.srw:115-153`, `right("000"+trim(codigo),4)`). El
 *    nombre del tratamiento se guarda **en mayúsculas** (`d_a_tratamiento_m.srd`: `edit.case=upper`, `edit.limit=100`).
 *  - **Un medicamento entra con cantidad 1**, la indicación del vademécum (`uso`), su `NOMBRE/presentación` en
 *    `descripcion` y como `orden` la cuenta de las filas que ya había —desde **0**— (`w_insitu_detalle.srw:290-312`).
 *    Las columnas que el escritorio deja editar de un medicamento ya cargado son la cantidad y el orden
 *    (`d_a_detalle_trata.srd`); la web deja editar también las indicaciones, que es lo que el médico necesita.
 *  - **Quitar** un medicamento es `deleterow` + `update` sobre la clave `(codigo, codemedicina)`: un medicamento
 *    una sola vez por tratamiento.
 *
 * ## Cómo escribe
 *
 * Compara lo que llega con lo que hay **por `codemedicina`** y escribe **sólo lo que cambió**, columna por columna,
 * con el last-write-wins del sync. El `orden` es la posición en la lista (desde 0, como el escritorio): las filas que
 * ya tienen la posición que les toca no se tocan, aunque se agregue uno al final. Todo en una transacción: el
 * tratamiento no queda a medias.
 *
 * ## El rastro, y por qué cada fila lleva su `created`
 *
 * `recipe_grupo` y `recipe_grupo_detalle` **no tienen clave primaria** en el PowerBuilder: su registro de cambios sube
 * la tabla entera (`op = T`) y `CambiosEscritorio::reemplazarTabla` borra todo lo del médico antes de insertar lo que
 * llegó. Por eso la Action anota un `created` **por fila** (el encabezado bajo `recipe_grupo`, cada medicamento bajo
 * `recipe_grupo_detalle`, con el id de la fila) y `config/sync_legado.php → sin_clave_con_altas_del_app` le enseña al
 * reemplazo a respetarlos (trampa 56 de `TRAMPAS.md`). Las ediciones dejan su `updated` por columna y las bajas, su
 * `deleted` (`RastroDeTratamientos`).
 */
final class GuardarTratamiento
{
    use RastroDeTratamientos;

    /** `recipe_grupo.tratamiento` es `char(100)`. */
    public const LARGO_NOMBRE = 100;

    /** `recipe_grupo_detalle.descripcion` es `varchar(100)`. */
    public const LARGO_DESCRIPCION = 100;

    /**
     * @param  string  $regMedico  el registro con el que se guarda: el del contexto
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no repetir nombre
     * @param  RecipeGrupo|null  $tratamiento  el que se corrige, o `null` para crear uno
     * @param  array<int,array{codemedicina:string, cantidad?:?int, indicaciones?:?string, descripcion?:?string, nombre?:?string}>  $items
     *         los medicamentos **en el orden en que quedan**, ya comprobados contra los catálogos del médico
     *         (`RecipesDeLaConsulta::resolverItemsDelMedico`). `nombre` es sólo para contar lo que pasó
     *
     * @throws InvalidArgumentException si falta el nombre, pasa del tope, ya hay otro tratamiento igual o el catálogo está lleno
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        ?RecipeGrupo $tratamiento,
        string $nombre,
        array $items,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeTratamiento {
        $nombre = ReglasDelCatalogo::descripcion($nombre, self::LARGO_NOMBRE, 'tratamiento');
        $items = ItemsDeRecipe::normalizar($items);
        $cuando = $this->ahora($cuando);

        return DB::transaction(fn () => $this->guardar($regMedico, $registrosMedicos, $tratamiento, $nombre, $items, $cuando, $origen));
    }

    private function guardar(
        string $regMedico,
        array $registrosMedicos,
        ?RecipeGrupo $tratamiento,
        string $nombre,
        array $items,
        Carbon $cuando,
        ?string $origen,
    ): ResultadoDeTratamiento {
        $registros = array_values(array_unique(array_merge($registrosMedicos, [$regMedico])));

        $grupo = $tratamiento === null
            ? null
            : RecipeGrupo::whereKey($tratamiento->id)->lockForUpdate()->firstOrFail();

        $this->nombreLibre($registros, $grupo, $nombre);

        $bloqueados = [];
        $nombreCambiado = false;

        if ($grupo === null) {
            $codigo = ReglasDelCatalogo::siguienteCodigo(RecipeGrupo::whereIn('reg_medico', $registros)->pluck('codigo'), 'tratamientos');
            $grupo = $this->crearFila(RecipeGrupo::class, 'recipe_grupo', [
                'reg_medico' => $regMedico,
                'codigo' => $codigo,
                'tratamiento' => $nombre,
            ], $cuando, $origen);
            $existentes = collect();
            $esNuevo = true;
        } else {
            $esNuevo = false;

            // Un nombre que sólo difiere en las mayúsculas no es un cambio (los de antes del DataWindow en mayúsculas).
            if (mb_strtoupper(trim((string) $grupo->tratamiento)) !== $nombre) {
                [$escritas, $noPisadas] = $this->corregirFila($grupo, 'recipe_grupo', ['tratamiento' => $nombre], $cuando, $origen);
                $nombreCambiado = $escritas !== [];
                if ($noPisadas !== []) {
                    $bloqueados[] = 'el nombre';
                }
            }

            $existentes = RecipeGrupoDetalle::where('reg_medico', $grupo->reg_medico)
                ->where('codigo', trim((string) $grupo->codigo))
                ->orderBy('orden')
                ->orderBy('id')
                ->get();
        }

        $codigoDelTratamiento = trim((string) $grupo->codigo);
        $ordenAnterior = $existentes->map(fn ($fila) => trim((string) $fila->codemedicina))->all();

        $sinEmparejar = $existentes->values()->all();
        $agregados = [];
        $modificados = [];
        $conservados = [];

        foreach ($items as $posicion => $item) {
            $encontrada = null;
            foreach ($sinEmparejar as $i => $fila) {
                if (trim((string) $fila->codemedicina) === $item['codemedicina']) {
                    $encontrada = $i;
                    break;
                }
            }

            if ($encontrada === null) {
                $this->crearFila(RecipeGrupoDetalle::class, 'recipe_grupo_detalle', [
                    'reg_medico' => $grupo->reg_medico,
                    'codigo' => $codigoDelTratamiento,
                    'codemedicina' => $item['codemedicina'],
                    'descripcion' => $item['descripcion'] === null ? null : mb_substr($item['descripcion'], 0, self::LARGO_DESCRIPCION),
                    'indicaciones' => $item['indicaciones'],
                    'cantidad' => $item['cantidad'] ?? 1,
                    'orden' => $posicion,
                ], $cuando, $origen);
                $agregados[] = $item['nombre'];
                continue;
            }

            /** @var RecipeGrupoDetalle $fila */
            $fila = $sinEmparejar[$encontrada];
            unset($sinEmparejar[$encontrada]);
            $conservados[] = $item['codemedicina'];

            [$escritas, $noPisadas] = $this->corregirFila($fila, 'recipe_grupo_detalle', [
                'cantidad' => $item['cantidad'] ?? 1,
                'indicaciones' => $item['indicaciones'],
                'orden' => $posicion,
            ], $cuando, $origen);

            if (array_intersect($escritas, ['cantidad', 'indicaciones']) !== []) {
                $modificados[] = $item['nombre'];
            }
            if ($noPisadas !== []) {
                $bloqueados[] = $item['nombre'];
            }
        }

        // Lo que ya no viene: se quita la fila (y deja su `deleted` para el teléfono).
        $quitados = [];
        foreach ($sinEmparejar as $fila) {
            $quitados[] = $this->nombreDe($fila, $items);
            $this->bajaDelDetalle($fila, $cuando, $origen);
        }

        // ¿Cambió el orden relativo de los que se conservaron? Renumerar por una baja no es "reordenar".
        $anteriorConservados = array_values(array_filter($ordenAnterior, fn ($codigo) => in_array($codigo, $conservados, true)));

        return new ResultadoDeTratamiento(
            tratamiento: $grupo,
            esNuevo: $esNuevo,
            nombreCambiado: $nombreCambiado,
            agregados: $agregados,
            modificados: array_values(array_unique($modificados)),
            quitados: $quitados,
            reordenado: $anteriorConservados !== $conservados,
            bloqueados: array_values(array_unique($bloqueados)),
        );
    }

    /**
     * No hay dos tratamientos con el mismo nombre (sin distinguir tildes ni mayúsculas) en el catálogo del médico:
     * dos "CONTROL PRENATAL" no se distinguen en la lista del récipe.
     *
     * @param  array<int,string>  $registros
     */
    private function nombreLibre(array $registros, ?RecipeGrupo $grupo, string $nombre): void
    {
        $clave = BusquedaDePacientes::normalizar($nombre);

        $otro = RecipeGrupo::whereIn('reg_medico', $registros)
            ->when($grupo !== null, fn ($consulta) => $consulta->where('id', '<>', $grupo->id))
            ->get()
            ->first(fn (RecipeGrupo $candidato) => BusquedaDePacientes::normalizar(trim((string) $candidato->tratamiento)) === $clave);

        if ($otro !== null) {
            throw new InvalidArgumentException(
                'Ya tienes un tratamiento llamado «' . trim((string) $otro->tratamiento) . '» (código ' . trim((string) $otro->codigo)
                . '). Ábrelo para editarlo o usa otro nombre.'
            );
        }
    }

    /** El nombre con el que se cuenta una fila que se quitó: el del formulario si lo hay, o el que guardó. */
    private function nombreDe(RecipeGrupoDetalle $fila, array $items): string
    {
        return ItemsDeRecipe::texto($fila->descripcion) ?? trim((string) $fila->codemedicina);
    }
}
