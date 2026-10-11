<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\RecipePareja;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar el récipe de la pareja de una consulta** (WEB-5.1b, dentro del ítem 10 del panel del legado):
 * **crearlo** si la consulta no tiene o **corregirlo en el lugar** si ya tiene.
 *
 * ## La forma de lo que escribe (verificada en la fuente)
 *
 * `recipes_pareja` no tiene cabecera ni columna `recipe`: la pareja es **una por consulta** y cada
 * medicamento es una fila con `nrohistoria`, `nroconsulta`, `codemedicina`, `indicaciones`, `cantidad`,
 * `descripcion`, `orden` y —repetidos en **todas** las filas— la `cedula` y el `nombre` de la pareja (el
 * `INSERT` de `w_consulta_recipe_morado.srw:462-480`). La ventana no deja agregar un medicamento sin esos dos
 * datos (`:444`, «Cargue los datos de la pareja»): acá son obligatorios, con los topes del esquema
 * (`cedula varchar(10)`, `nombre varchar(200)`).
 *
 * Los medicamentos se escriben **igual que los del récipe del paciente** (`GuardarRecipe`): la misma
 * normalización (`ItemsDeRecipe`) y la misma convención de `descripcion` (`NOMBRE/presentación`), `cantidad`
 * (1 por defecto) y `orden`. El `orden` acá empieza en **1** y el escritorio lo escribe desde **0**
 * (`ord = dw_9.rowcount()`, `:456`); las filas nuevas siguen al mayor `orden` que ya haya y las viejas no se
 * renumeran.
 *
 * ## Crear y corregir
 *
 *  - **Sin filas, crea**: una fila por medicamento, todas con la cédula y el nombre de la pareja.
 *  - **Con filas, corrige en el lugar**, sin límite de tiempo: compara lo que llega con lo que hay **por
 *    `codemedicina`** y escribe **sólo lo que cambió** —la cantidad y las indicaciones de una fila que ya
 *    estaba, la cédula y el nombre en **todas** las filas si cambiaron, las filas nuevas con el `orden`
 *    siguiente y la baja de las que ya no vienen—. **Editar las indicaciones no toca el vademécum**.
 *
 * Los datos de la pareja **no se enlazan con `pacientes`** (decisión del usuario, 2026-10-11): son texto libre.
 * Cada edición pasa por Eloquent (`->save()`), así que **cambia `updated_at`**.
 *
 * ## El rastro del sync
 *
 * Con `$origen` (la web pasa `web`) deja el rastro en `sync_changes`: `updated` **por columna** (`cantidad`,
 * `indicaciones`, `cedula`, `nombre`) con el last-write-wins del sync (`RegistroDeCambios::permite`) y
 * `deleted` por fila. Y un `created` **por fila nueva** con `table_name = 'recipes_pareja'` y el id de la
 * fila: es lo que protege las filas de la web del reemplazo de la tabla que hace el escritorio.
 * `recipes_pareja` no tiene clave primaria en el PowerBuilder, así que su registro de cambios sube la tabla
 * **entera** (`op = T`) y `CambiosEscritorio::reemplazarTabla` borra todo lo del médico antes de insertar lo
 * que llegó; `config/sync_legado.php → sin_clave_con_altas_del_app` le enseña a respetar los `created` de
 * esta tabla (trampa 43 de `TRAMPAS.md`). El móvil no escribe ni lee `recipes_pareja`.
 */
final class GuardarRecipeDeLaPareja
{
    /** `recipes_pareja.cedula` es `varchar(10)`. */
    public const LARGO_CEDULA = 10;

    /** `recipes_pareja.nombre` es `varchar(200)`. */
    public const LARGO_NOMBRE = 200;

    /**
     * @param  Consulta  $consulta  ya resuelta contra el contexto: de ahí salen `reg_medico`, `numhistoria` y `nroconsulta`
     * @param  array<int,array{codemedicina:string, cantidad?:?int, indicaciones?:?string, descripcion?:?string, nombre?:?string}>  $items
     *         los medicamentos del récipe, en orden. `nombre` es sólo para contar lo que pasó, no se escribe
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si falta la cédula o el nombre de la pareja, si pasan del tope o si no hay ningún medicamento
     */
    public function ejecutar(
        Consulta $consulta,
        string $cedula,
        string $nombre,
        array $items,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeRecipeDeLaPareja {
        $cedula = trim($cedula);
        $nombre = trim($nombre);

        if ($cedula === '' || $nombre === '') {
            throw new InvalidArgumentException('Cargue los datos de la pareja: la cédula y el nombre son obligatorios.');
        }
        if (mb_strlen($cedula) > self::LARGO_CEDULA || mb_strlen($nombre) > self::LARGO_NOMBRE) {
            throw new InvalidArgumentException('La cédula admite hasta ' . self::LARGO_CEDULA . ' caracteres y el nombre hasta ' . self::LARGO_NOMBRE . '.');
        }

        $items = ItemsDeRecipe::normalizar($items);

        if ($items === []) {
            throw new InvalidArgumentException('El récipe de la pareja no tiene medicamentos.');
        }

        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();

        return DB::transaction(fn () => $this->guardar($consulta, $cedula, $nombre, $items, $cuando, $origen));
    }

    private function guardar(Consulta $consulta, string $cedula, string $nombre, array $items, Carbon $cuando, ?string $origen): ResultadoDeRecipeDeLaPareja
    {
        $regMedico = (string) $consulta->reg_medico;
        $existentes = $this->filas($consulta);

        $bloqueados = [];
        $modificados = [];
        $quitados = [];
        $agregados = [];
        $creadas = [];
        $parejaCambiada = false;

        // 1. Lo que ya estaba (por `codemedicina`): la cantidad y las indicaciones, y la pareja en la fila. Lo
        //    que queda sin emparejar al final es lo que el médico quitó.
        $sinEmparejar = $existentes->values()->all();
        $nuevos = [];

        foreach ($items as $item) {
            $posicion = null;
            foreach ($sinEmparejar as $i => $fila) {
                if (trim((string) $fila->codemedicina) === $item['codemedicina']) {
                    $posicion = $i;
                    break;
                }
            }

            if ($posicion === null) {
                $nuevos[] = $item;
                continue;
            }

            /** @var RecipePareja $fila */
            $fila = $sinEmparejar[$posicion];
            unset($sinEmparejar[$posicion]);

            $deLaFila = [];
            if (array_key_exists('cantidad', $item) && ItemsDeRecipe::entero($fila->cantidad) !== $item['cantidad']) {
                $deLaFila['cantidad'] = $item['cantidad'];
            }
            if (array_key_exists('indicaciones', $item) && ItemsDeRecipe::texto($fila->indicaciones) !== $item['indicaciones']) {
                $deLaFila['indicaciones'] = $item['indicaciones'];
            }

            // La pareja va en **todas** las filas: si cambió, cada una la reescribe.
            $deLaPareja = [];
            if (ItemsDeRecipe::texto($fila->cedula) !== $cedula) {
                $deLaPareja['cedula'] = $cedula;
            }
            if (ItemsDeRecipe::texto($fila->nombre) !== $nombre) {
                $deLaPareja['nombre'] = $nombre;
            }

            // El last-write-wins del sync decide **por columna**: una edición más nueva no se pisa, pero eso
            // no impide escribir la otra columna de la misma fila.
            $aplicados = [];
            foreach (array_merge($deLaFila, $deLaPareja) as $columna => $valor) {
                if (! $this->permite($origen, (int) $fila->id, $columna, $cuando)) {
                    $bloqueados[] = $item['nombre'];
                    continue;
                }

                $fila->{$columna} = $valor;
                $aplicados[$columna] = $valor;
            }

            if ($aplicados !== []) {
                $fila->save();

                foreach ($aplicados as $columna => $valor) {
                    $this->registrar($origen, (int) $fila->id, $regMedico, $columna, $valor, $cuando);
                }

                if (array_intersect_key($aplicados, $deLaFila) !== []) {
                    $modificados[] = $item['nombre'];
                }
                if (array_intersect_key($aplicados, $deLaPareja) !== []) {
                    $parejaCambiada = true;
                }
            }
        }

        // 2. Lo que ya no viene: se quita la fila.
        foreach ($sinEmparejar as $fila) {
            $id = (int) $fila->id;
            $quitados[] = $this->nombreDeLaFila($fila, $items);

            $fila->delete();

            if ($origen !== null) {
                RegistroDeCambios::registrarOperacion('recipes_pareja', $id, $regMedico, 'deleted', null, null, $cuando, $origen);
            }
        }

        // 3. Lo nuevo: al final, con el `orden` siguiente (las filas del escritorio no se renumeran). El
        //    escritorio numera desde 0 (`orden = rowcount`) y la web desde 1: se sigue al mayor que haya y, si
        //    ninguna fila trae `orden`, a la cuenta de filas.
        $orden = $existentes->max('orden') === null ? $existentes->count() : (int) $existentes->max('orden');

        foreach ($nuevos as $item) {
            $fila = RecipePareja::create([
                'reg_medico' => $regMedico,
                'nrohistoria' => $consulta->numhistoria,
                'nroconsulta' => $consulta->nroconsulta,
                'codemedicina' => $item['codemedicina'],
                'indicaciones' => $item['indicaciones'],
                'cantidad' => $item['cantidad'],
                'descripcion' => $item['descripcion'],
                'orden' => ++$orden,
                'cedula' => $cedula,
                'nombre' => $nombre,
            ]);

            // El `created` de la fila: lo que la protege del reemplazo de la tabla que sube el escritorio.
            if ($origen !== null) {
                RegistroDeCambios::registrarOperacion('recipes_pareja', (int) $fila->id, $regMedico, 'created', null, null, $cuando, $origen);
            }

            $creadas[] = $fila;
            $agregados[] = $item['nombre'];
        }

        return new ResultadoDeRecipeDeLaPareja(
            esNuevo: $existentes->isEmpty(),
            filas: collect($creadas),
            agregados: $agregados,
            modificados: array_values(array_unique($modificados)),
            quitados: $quitados,
            parejaCambiada: $parejaCambiada,
            bloqueados: array_values(array_unique($bloqueados)),
        );
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return Collection<int,RecipePareja> las filas de la pareja de la consulta */
    private function filas(Consulta $consulta): Collection
    {
        return RecipePareja::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    /** El nombre con el que se cuenta una fila que se quitó: el que mandó el formulario o, si no, el suyo. */
    private function nombreDeLaFila(RecipePareja $fila, array $items): string
    {
        $codigo = trim((string) $fila->codemedicina);

        foreach ($items as $item) {
            if ($item['codemedicina'] === $codigo) {
                return $item['nombre'];
            }
        }

        return ItemsDeRecipe::texto($fila->descripcion) ?? $codigo;
    }

    /** Sin `$origen` no hay rastro y, por lo tanto, nada contra qué decidir: se escribe. */
    private function permite(?string $origen, int $registro, string $columna, Carbon $cuando): bool
    {
        return $origen === null || RegistroDeCambios::permite('recipes_pareja', $registro, $columna, $cuando);
    }

    private function registrar(?string $origen, int $registro, string $regMedico, string $columna, $valor, Carbon $cuando): void
    {
        if ($origen !== null) {
            RegistroDeCambios::registrar('recipes_pareja', $registro, $regMedico, $columna, $valor, $cuando, $origen);
        }
    }
}
