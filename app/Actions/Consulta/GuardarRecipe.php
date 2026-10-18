<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\FechaDelConsultorio;
use App\Models\Consulta;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar un récipe de una consulta** (WEB-5.1, ítem 10 del panel del legado): **crear** uno nuevo o
 * **editar** en el lugar uno que ya existe. Es la regla de escritura del récipe y la comparten la web y el
 * sync del móvil (PLAN-WEB.md, R3): `CreacionesClinicas::crearRecipe` la llama con `$numero = null`, así que
 * el número, la cabecera y la forma de las filas **se escriben una sola vez**.
 *
 * ## La forma de lo que escribe (verificada en la fuente)
 *
 * Un récipe son **una cabecera en `recipe_detalle`** (`nrohistoria`, `nroconsulta`, `recipe`, `fe_emision`
 * y `fe_vence = 30`: lo que hace `ue_fecha` del escritorio, `w_consulta_principal.srw:12343-12352`) y
 * **una fila de `recipes` por medicamento**, todas con el mismo `recipe` (el `INSERT` del botón *Pasar*,
 * `:13357-13373`, y el de un tratamiento, `:13399-13415`): `codemedicina`, `cantidad`, `indicaciones`,
 * `descripcion` (el `NOMBRE/presentación` que el escritorio copia del vademécum), `orden` y `fecha`. El
 * escritorio crea un solo récipe por consulta y no escribe `recipe` ni `fecha` en las filas; el móvil —y
 * ahora la web— numera 1, 2, 3… por consulta.
 *
 * ## Crear y editar
 *
 *  - **`$numero === null` crea**: el siguiente número de la consulta (`RecipesDeLaConsulta::siguienteNumero`),
 *    su cabecera y sus filas con `orden` 1..n.
 *  - **`$numero` dado edita ese récipe en el lugar**, sin límite de tiempo: compara lo que llega con lo que
 *    hay **por `codemedicina`** (la clave del esquema del escritorio) y escribe **sólo lo que cambió** —la
 *    cantidad y las indicaciones de una fila que ya estaba, las filas nuevas con el `orden` siguiente, la
 *    baja de las que ya no vienen y la fecha de emisión—. **Editar las indicaciones de una fila no toca el
 *    vademécum ni el tratamiento**: son de ese récipe (wiki `05-recipes-y-solicitudes.md`). Las filas del
 *    escritorio con `recipe` en `NULL` son el récipe 1 y **no se renumeran**: sólo las filas **nuevas** de
 *    ese récipe llevan `recipe = 1`.
 *
 * Cada edición pasa por Eloquent (`->save()`), así que **cambia `updated_at`**, que es lo que el móvil usa
 * para bajar el delta de `recipes`.
 *
 * ## El rastro del sync
 *
 * Con `$origen` (la web pasa `web`) deja el rastro en `sync_changes`: `updated` **por columna**
 * (`cantidad`, `indicaciones`, `fecha`) con el last-write-wins del sync (`RegistroDeCambios::permite`), y
 * `deleted` por fila (lo que le avisa al teléfono que la suelte). Del `created` hay una convención heredada
 * del móvil que **no se cambia**: el `created` de `recipes` es el de la **cabecera** (`record_id` = el id de
 * `recipe_detalle`), porque el reemplazo de `recipe_detalle` que hace el escritorio (tabla sin clave
 * primaria) conserva las cabeceras que anotó el app (`config/sync_legado.php` →
 * `sin_clave_con_altas_del_app`). Por eso **no** se anota un `created` por fila: un `record_id` de fila
 * podría coincidir con el de una cabecera ajena y blindarla. El móvil pasa `$origen = null`: anota su
 * propio `created` con el `client_temp_id` del lote (`CreacionesClinicas::anotar`) y el comportamiento del
 * sync no cambia.
 */
final class GuardarRecipe
{
    public function __construct(private RecipesDeLaConsulta $lectura)
    {
    }

    /**
     * @param  Consulta  $consulta  ya resuelta contra el contexto o el médico del sync: de ahí salen
     *                              `reg_medico`, `numhistoria` y `nroconsulta`
     * @param  array<int,array{codemedicina:string, cantidad?:?int, indicaciones?:?string, descripcion?:?string, nombre?:?string}>  $items
     *         los medicamentos del récipe, en orden. `nombre` es sólo para contar lo que pasó, no se escribe
     * @param  string|null  $fecha  `AAAA-MM-DD`: la fecha de emisión. Al crear, por defecto **hoy del
     *                              consultorio** (O-13: es la fecha del documento, no la de la consulta); al
     *                              editar, `null` la deja como está
     * @param  int|null  $numero  `null` crea un récipe nuevo; un número edita ese récipe
     * @param  string|null  $origen  `web` / `mobile`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si se edita un récipe que no existe o no hay ningún medicamento
     */
    public function ejecutar(
        Consulta $consulta,
        array $items,
        ?string $fecha = null,
        ?int $numero = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeRecipe {
        $items = $this->normalizar($items);

        if ($items === []) {
            throw new InvalidArgumentException('El récipe no tiene medicamentos.');
        }

        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $fecha = $this->fechaValida($fecha);

        return DB::transaction(fn () => $numero === null
            ? $this->crear($consulta, $items, $fecha, $cuando, $origen)
            : $this->editar($consulta, $numero, $items, $fecha, $cuando, $origen));
    }

    // ------------------------------------------------------------------------------------------ Crear

    private function crear(Consulta $consulta, array $items, ?string $fecha, Carbon $cuando, ?string $origen): ResultadoDeRecipe
    {
        // O-13: la fecha de emisión es la **del documento** (hoy del consultorio), no la de la consulta que
        // se está editando. `FechaDelConsultorio` y no `$cuando`: a las 20:30 de Caracas el día del servidor
        // ya es otro (trampa 59).
        $fecha ??= FechaDelConsultorio::hoyComoFecha()->toDateString();

        $numero = $this->lectura->siguienteNumero($consulta);

        $detalle = RecipeDetalle::create([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'recipe' => $numero,
            'fe_emision' => $fecha,
            // Como el legado: el récipe vence a los 30 días.
            'fe_vence' => RecipesDeLaConsulta::VIGENCIA_EN_DIAS,
        ]);

        $filas = [];
        foreach ($items as $indice => $item) {
            $filas[] = $this->nuevaFila($consulta, $numero, $item, $indice + 1, $fecha);
        }

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion('recipes', $detalle->id, (string) $consulta->reg_medico, 'created', null, null, $cuando, $origen);
        }

        return new ResultadoDeRecipe(
            numero: $numero,
            esNuevo: true,
            cabecera: $detalle,
            filas: collect($filas),
            agregados: array_map(fn (array $item) => $item['nombre'], $items),
        );
    }

    // ------------------------------------------------------------------------------------------ Editar

    private function editar(Consulta $consulta, int $numero, array $items, ?string $fecha, Carbon $cuando, ?string $origen): ResultadoDeRecipe
    {
        $regMedico = (string) $consulta->reg_medico;

        $existentes = $this->filasDelRecipe($consulta, $numero);
        $cabecera = RecipeDetalle::where('reg_medico', $regMedico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('recipe', $numero)
            ->orderBy('id')
            ->first();

        if ($existentes->isEmpty() && $cabecera === null) {
            throw new InvalidArgumentException('El récipe ' . $numero . ' no existe en esta consulta.');
        }

        $bloqueados = [];
        $modificados = [];
        $quitados = [];
        $agregados = [];
        $creadas = [];
        $fechaCambiada = false;

        // La fecha de emisión que tiene hoy el récipe: la de la cabecera y, si no hay, la de sus filas.
        $actual = $this->soloFecha($cabecera?->fe_emision)
            ?? $existentes->map(fn (Recipe $fila) => $this->soloFecha($fila->getRawOriginal('fecha')))->first(fn ($f) => $f !== null);

        $vigente = $fecha ?? $actual ?? FechaDelConsultorio::hoyComoFecha()->toDateString();

        // 1. La fecha de emisión: la cabecera y la fecha de cada fila (el escritorio la escribe en las dos).
        if ($fecha !== null && $fecha !== $actual) {
            $fechaCambiada = true;

            if ($cabecera === null) {
                $cabecera = RecipeDetalle::create([
                    'reg_medico' => $regMedico,
                    'nrohistoria' => $consulta->numhistoria,
                    'nroconsulta' => $consulta->nroconsulta,
                    'recipe' => $numero,
                    'fe_emision' => $fecha,
                    'fe_vence' => RecipesDeLaConsulta::VIGENCIA_EN_DIAS,
                ]);

                if ($origen !== null) {
                    RegistroDeCambios::registrarOperacion('recipes', $cabecera->id, $regMedico, 'created', null, null, $cuando, $origen);
                }
            } elseif ($this->soloFecha($cabecera->fe_emision) !== $fecha) {
                if (! $this->permite($origen, 'recipe_detalle', (int) $cabecera->id, 'fe_emision', $cuando)) {
                    $bloqueados[] = 'la fecha de emisión';
                    $fechaCambiada = false;
                } else {
                    $cabecera->fe_emision = $fecha;
                    $cabecera->save();
                    $this->registrar($origen, 'recipe_detalle', (int) $cabecera->id, $regMedico, 'fe_emision', $fecha, $cuando);
                }
            }

            if ($fechaCambiada) {
                foreach ($existentes as $fila) {
                    if ($this->soloFecha($fila->getRawOriginal('fecha')) === $fecha) {
                        continue;
                    }

                    if (! $this->permite($origen, 'recipes', (int) $fila->id, 'fecha', $cuando)) {
                        $bloqueados[] = $this->nombreDeLaFila($fila, $items);
                        continue;
                    }

                    $fila->fecha = $fecha;
                    $fila->save();
                    $this->registrar($origen, 'recipes', (int) $fila->id, $regMedico, 'fecha', $fecha, $cuando);
                }
            }
        }

        // 2. Lo que ya estaba (por `codemedicina`): cantidad e indicaciones, sólo si cambiaron. Lo que
        //    queda sin emparejar al final es lo que el médico quitó.
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

            /** @var Recipe $fila */
            $fila = $sinEmparejar[$posicion];
            unset($sinEmparejar[$posicion]);

            $cambios = [];
            if (array_key_exists('cantidad', $item) && $this->entero($fila->cantidad) !== $item['cantidad']) {
                $cambios['cantidad'] = $item['cantidad'];
            }
            if (array_key_exists('indicaciones', $item) && $this->texto($fila->indicaciones) !== $item['indicaciones']) {
                $cambios['indicaciones'] = $item['indicaciones'];
            }

            // El last-write-wins del sync decide **por columna**: una edición más nueva del escritorio no se
            // pisa, pero eso no impide escribir la otra columna de la misma fila.
            $aplicados = [];
            foreach ($cambios as $columna => $valor) {
                if (! $this->permite($origen, 'recipes', (int) $fila->id, $columna, $cuando)) {
                    $bloqueados[] = $item['nombre'];
                    continue;
                }

                $fila->{$columna} = $valor;
                $aplicados[$columna] = $valor;
            }

            if ($aplicados !== []) {
                $fila->save();

                foreach ($aplicados as $columna => $valor) {
                    $this->registrar($origen, 'recipes', (int) $fila->id, $regMedico, $columna, $valor, $cuando);
                }

                $modificados[] = $item['nombre'];
            }
        }

        // 3. Lo que ya no viene: se quita la fila (el teléfono lo sabe por el `deleted`).
        foreach ($sinEmparejar as $fila) {
            $nombre = $this->nombreDeLaFila($fila, $items);
            $id = (int) $fila->id;

            $fila->delete();

            if ($origen !== null) {
                RegistroDeCambios::registrarOperacion('recipes', $id, $regMedico, 'deleted', null, null, $cuando, $origen);
            }

            $quitados[] = $nombre;
        }

        // 4. Lo nuevo: al final, con el `orden` siguiente (las filas del escritorio no se renumeran).
        // El escritorio numera desde 0 (`orden = rowcount`, `:13351`) y el móvil desde 1: se sigue al mayor que
        // haya y, si ninguna fila trae `orden`, a la cuenta de filas.
        $orden = $existentes->max('orden') === null ? $existentes->count() : (int) $existentes->max('orden');

        foreach ($nuevos as $item) {
            $creadas[] = $this->nuevaFila($consulta, $numero, $item, ++$orden, $vigente);
            $agregados[] = $item['nombre'];
        }

        return new ResultadoDeRecipe(
            numero: $numero,
            esNuevo: false,
            cabecera: $cabecera,
            filas: collect($creadas),
            agregados: $agregados,
            modificados: array_values(array_unique($modificados)),
            quitados: $quitados,
            fechaCambiada: $fechaCambiada,
            bloqueados: array_values(array_unique($bloqueados)),
        );
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return Collection<int,Recipe> las filas del récipe, con el `NULL` del escritorio contando como el 1 */
    private function filasDelRecipe(Consulta $consulta, int $numero): Collection
    {
        return Recipe::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->whereRaw('COALESCE(recipe, 1) = ?', [$numero])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    private function nuevaFila(Consulta $consulta, int $numero, array $item, int $orden, ?string $fecha): Recipe
    {
        return Recipe::create([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'codemedicina' => $item['codemedicina'],
            'indicaciones' => $item['indicaciones'],
            'cantidad' => $item['cantidad'],
            'descripcion' => $item['descripcion'],
            'orden' => $orden,
            'fecha' => $fecha,
            'recipe' => $numero,
        ]);
    }

    /**
     * Los medicamentos como se escriben (la regla vive en `ItemsDeRecipe`, que comparte con el récipe de la
     * pareja, WEB-5.1b).
     *
     * @return array<int,array{codemedicina:string, cantidad:?int, indicaciones:?string, descripcion:?string, nombre:string}>
     */
    private function normalizar(array $items): array
    {
        return ItemsDeRecipe::normalizar($items);
    }

    /** El nombre con el que se cuenta una fila que ya estaba: el que mandó el formulario o, si no, el suyo. */
    private function nombreDeLaFila(Recipe $fila, array $items): string
    {
        $codigo = trim((string) $fila->codemedicina);

        foreach ($items as $item) {
            if ($item['codemedicina'] === $codigo) {
                return $item['nombre'];
            }
        }

        return $this->texto($fila->descripcion) ?? $codigo;
    }

    private function entero($valor): ?int
    {
        return ItemsDeRecipe::entero($valor);
    }

    /** `''` y `null` son el mismo estado: sin texto. */
    private function texto($valor): ?string
    {
        return ItemsDeRecipe::texto($valor);
    }

    private function fechaValida(?string $fecha): ?string
    {
        if ($fecha === null) {
            return null;
        }

        $parseada = \DateTime::createFromFormat('!Y-m-d', $fecha);

        return $parseada && $parseada->format('Y-m-d') === $fecha ? $fecha : null;
    }

    private function soloFecha($valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === null || str_starts_with($texto, '0000') ? null : substr($texto, 0, 10);
    }

    /** Sin `$origen` no hay rastro y, por lo tanto, nada contra qué decidir: se escribe. */
    private function permite(?string $origen, string $tabla, int $registro, string $columna, Carbon $cuando): bool
    {
        return $origen === null || RegistroDeCambios::permite($tabla, $registro, $columna, $cuando);
    }

    private function registrar(?string $origen, string $tabla, int $registro, string $regMedico, string $columna, $valor, Carbon $cuando): void
    {
        if ($origen !== null) {
            RegistroDeCambios::registrar($tabla, $registro, $regMedico, $columna, $valor, $cuando, $origen);
        }
    }
}
