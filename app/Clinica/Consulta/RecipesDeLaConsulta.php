<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Consulta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Los récipes de una consulta** (WEB-5.1, ítem 10 del panel del legado): lo que la consulta ya tiene
 * recetado, el vademécum del médico con su buscador, sus tratamientos (plantillas) y las reglas de
 * **cómo se agrupa y se numera** un récipe, que comparten la web y el sync del móvil.
 *
 * ## De qué cuelga y cómo se agrupa (verificado en la fuente)
 *
 * Un **medicamento** de un récipe es una fila de `recipes`, que cuelga de la consulta por
 * `nrohistoria` + `nroconsulta` (+ `codemedicina`: la clave del esquema del escritorio es
 * `(nrohistoria, nroconsulta, codemedicina)`). El DataWindow de la pestaña de récipes
 * (`d_medicamentos_recipe_con.srd`) se recupera con `retrieve(historia, consulta)`, cruza con `vademecum`
 * por `codemedicina` y muestra `compute_0007 = nombrecomercial + '/' + presentacion` (`w_consulta_principal.srw`
 * `dw_9`, `:3012-3013` y `:4566-4567`). Lo que **agrupa** las filas en récipes —`recipes.recipe`— es una
 * columna que el escritorio **no usa**: crea **un solo** récipe por consulta, con `recipe_detalle.recipe = 1`
 * (`ue_fecha`, `:12343-12352`), y de las 281.716 filas de `recipes` de la base local 281.630 tienen
 * `recipe` en `NULL`. El móvil sí numera (1, 2, 3…) y la web hace lo mismo que el móvil: la agrupación es
 * **`nrohistoria` + `nroconsulta` + `recipe`**, donde **`NULL` cuenta como el récipe 1** (es "el
 * récipe único" del escritorio). Esa regla —`numeroDeGrupo`— es **la** definición: la historia del paciente
 * (`HistoriaDelPaciente`) la usa para que el mismo récipe se vea igual en las dos pantallas.
 *
 * ## El número que sigue
 *
 * El siguiente es **el mayor** entre `recipes.recipe` y `recipe_detalle.recipe` **+ 1**, y los récipes
 * viejos con `recipe` en `NULL` cuentan como el 1. Es la regla que antes vivía adentro de
 * `CreacionesClinicas::siguienteRecipe` y ahora es de las dos superficies (PLAN-WEB.md, R3).
 *
 * ## Qué vademécum
 *
 * Es **`vademecum`**, el mismo que lee el móvil y el que usa el escritorio en esa pestaña
 * (`d_medicamentos_listado.srd` es `vademecum`; el alta `w_nuevo_vademecum` también). `vademecum_m` es una
 * copia que sólo usan `w_nuevo_vademecum_m` / `_mante` (una pantalla alterna con los **mismos** DataWindows
 * `d_b_vademecum` / `d_a_vademecum_go`, que actualizan `vademecum`). Ver la trampa 39 de `TRAMPAS.md`.
 *
 * Es **sólo lectura**: la escritura es `App\Actions\Consulta\{GuardarRecipe,EliminarRecipe}` y el alta del
 * medicamento que falta, `AgregarMedicamentoAlVademecum`.
 */
final class RecipesDeLaConsulta
{
    /** El tope de resultados del buscador: es para elegir, no para listar el vademécum entero. */
    public const MAX_RESULTADOS = 30;

    /** Los días de vigencia de un récipe, como el escritorio (`ue_fecha`: `fe_vence = 30`, `:12351`). */
    public const VIGENCIA_EN_DIAS = 30;

    /**
     * El récipe al que pertenece una fila: su `recipe`, o el **1** si es `NULL` (el récipe único del
     * escritorio). La única definición de la agrupación.
     */
    public static function numeroDeGrupo($recipe): int
    {
        return $recipe === null || $recipe === '' ? 1 : (int) $recipe;
    }

    /**
     * El número que le toca al récipe nuevo de una consulta: el mayor de `recipes.recipe` y
     * `recipe_detalle.recipe` + 1, con el `NULL` del escritorio contando como el 1.
     */
    public function siguienteNumero(Consulta $consulta): int
    {
        $filtro = fn ($query) => $query
            ->where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta);

        $ultimo = max(
            (int) $filtro(DB::table('recipes'))->max('recipe'),
            (int) $filtro(DB::table('recipe_detalle'))->max('recipe'),
        );

        // Los récipes viejos del legado tienen `recipe` NULL (uno por consulta): cuentan como el 1.
        if ($ultimo === 0 && $filtro(DB::table('recipes'))->exists()) {
            $ultimo = 1;
        }

        return $ultimo + 1;
    }

    /** ¿Existe ese récipe en la consulta (alguna fila o su cabecera)? Es lo que decide el 404 de quien llama. */
    public function existe(Consulta $consulta, int $numero): bool
    {
        $filtro = fn ($query) => $query
            ->where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta);

        return $filtro(DB::table('recipes'))->whereRaw('COALESCE(recipe, 1) = ?', [$numero])->exists()
            || $filtro(DB::table('recipe_detalle'))->where('recipe', $numero)->exists();
    }

    /**
     * Los récipes de **esa** consulta, agrupados como el legado y con el nombre de cada medicamento
     * resuelto por el vademécum, en orden de número.
     *
     * @return Collection<int,object{numero:int, fecha:?string, vigencia:int, cabecera:bool, items:Collection<int,object>}>
     */
    public function deLaConsulta(string $regMedico, int $numhistoria, int $nroconsulta): Collection
    {
        $filas = DB::table('recipes')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numhistoria)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'recipe', 'codemedicina', 'cantidad', 'indicaciones', 'orden', 'descripcion', 'fecha']);

        $cabeceras = DB::table('recipe_detalle')
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $numhistoria)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('id')
            ->get(['id', 'recipe', 'fe_emision', 'fe_vence'])
            ->groupBy(fn ($cabecera) => (int) $cabecera->recipe)
            ->map(fn (Collection $iguales) => $iguales->first());

        $catalogo = $this->vademecumPorCodigo($regMedico, $filas->pluck('codemedicina')->all());

        $porNumero = $filas->groupBy(fn ($fila) => self::numeroDeGrupo($fila->recipe));

        // Una cabecera sin ninguna fila también es un récipe (el escritorio crea la cabecera al imprimir): se
        // muestra vacía y se puede completar o eliminar.
        $numeros = $porNumero->keys()->merge($cabeceras->keys())->unique()->sort()->values();

        return $numeros->map(function (int $numero) use ($porNumero, $cabeceras, $catalogo) {
            $filas = $porNumero->get($numero, collect());
            $cabecera = $cabeceras->get($numero);

            $fecha = $this->soloFecha($cabecera->fe_emision ?? null)
                ?? $this->soloFecha($filas->pluck('fecha')->first(fn ($fecha) => $this->soloFecha($fecha) !== null));

            return (object) [
                'numero' => $numero,
                'fecha' => $fecha,
                'vigencia' => (int) ($cabecera->fe_vence ?? 0) > 0 ? (int) $cabecera->fe_vence : self::VIGENCIA_EN_DIAS,
                'cabecera' => $cabecera !== null,
                'items' => $filas->map(fn ($fila) => $this->item($fila, $catalogo[trim((string) $fila->codemedicina)] ?? null))->values(),
            ];
        })->values();
    }

    /**
     * Los medicamentos del vademécum del médico que coinciden con lo que se escribió: por **nombre
     * comercial o genérico** (y por las marcas alternas y la presentación), **sin distinguir tildes ni
     * mayúsculas**, con todas las palabras. Un medicamento por `nombre/presentación` (el de código más
     * bajo: el vademécum del legado trae repetidos, misma regla que los catálogos de motivos y de
     * diagnósticos).
     *
     * La comparación no es un `LIKE` de SQL: el de SQLite sólo pliega las mayúsculas ASCII (trampa 13).
     * Se normaliza con `BusquedaDePacientes::normalizar()`, el único mapa de acentos del proyecto.
     *
     * @return Collection<int,object{codigo:string, nombre:string, comercial:?string, generico:?string, presentacion:?string, uso:?string}>
     */
    public function medicamentos(string $regMedico, string $buscar = ''): Collection
    {
        $palabras = $this->palabras($buscar);

        return DB::table('vademecum')
            ->where('reg_medico', $regMedico)
            ->orderBy('codemedicina')
            ->get(['codemedicina', 'nombrecomercial', 'nombregenerico', 'presentacion', 'uso',
                'nombrecomercial1', 'nombrecomercial2', 'nombrecomercial3'])
            ->filter(function ($fila) use ($palabras) {
                if ($palabras === []) {
                    return true;
                }

                $pajar = BusquedaDePacientes::normalizar(implode(' ', array_filter([
                    $fila->nombrecomercial, $fila->nombregenerico, $fila->presentacion,
                    $fila->nombrecomercial1, $fila->nombrecomercial2, $fila->nombrecomercial3,
                ], fn ($valor) => CamposDePaciente::texto($valor) !== null)));

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            // Un medicamento sin ningún nombre no se ofrece: no hay cómo reconocerlo en la lista.
            ->filter(fn ($fila) => CamposDePaciente::texto($fila->nombrecomercial) !== null
                || CamposDePaciente::texto($fila->nombregenerico) !== null)
            ->groupBy(fn ($fila) => BusquedaDePacientes::normalizar(
                (CamposDePaciente::texto($fila->nombrecomercial) ?? CamposDePaciente::texto($fila->nombregenerico))
                . '|' . (CamposDePaciente::texto($fila->presentacion) ?? '')
            ))
            ->map(fn (Collection $iguales) => $iguales->sortBy('codemedicina')->first())
            ->sortBy(fn ($fila) => $this->puntaje($fila, $palabras))
            ->values()
            ->take(self::MAX_RESULTADOS)
            ->map(fn ($fila) => $this->medicamento($fila));
    }

    /**
     * Los tratamientos (plantillas) del médico que coinciden con el nombre, cada uno con sus
     * medicamentos, cantidad e indicaciones por defecto y el nombre ya resuelto. Sin texto, todos.
     *
     * @return Collection<int,object{codigo:string, nombre:string, items:Collection<int,object>}>
     */
    public function tratamientos(string $regMedico, string $buscar = ''): Collection
    {
        $palabras = $this->palabras($buscar);

        $grupos = DB::table('recipe_grupo')
            ->where('reg_medico', $regMedico)
            ->orderBy('tratamiento')
            ->get(['codigo', 'tratamiento'])
            ->filter(function ($grupo) use ($palabras) {
                $nombre = BusquedaDePacientes::normalizar((string) $grupo->tratamiento);

                foreach ($palabras as $palabra) {
                    if (! str_contains($nombre, $palabra)) {
                        return false;
                    }
                }

                return CamposDePaciente::texto($grupo->tratamiento) !== null;
            })
            ->take(self::MAX_RESULTADOS)
            ->values();

        if ($grupos->isEmpty()) {
            return collect();
        }

        $detalles = DB::table('recipe_grupo_detalle')
            ->where('reg_medico', $regMedico)
            ->whereIn('codigo', $grupos->pluck('codigo')->all())
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['codigo', 'codemedicina', 'descripcion', 'indicaciones', 'cantidad'])
            ->groupBy(fn ($detalle) => trim((string) $detalle->codigo));

        $catalogo = $this->vademecumPorCodigo($regMedico, $detalles->flatten()->pluck('codemedicina')->all());

        return $grupos->map(fn ($grupo) => (object) [
            'codigo' => trim((string) $grupo->codigo),
            'nombre' => trim((string) $grupo->tratamiento),
            'items' => ($detalles->get(trim((string) $grupo->codigo)) ?? collect())->map(function ($detalle) use ($catalogo) {
                $delCatalogo = $catalogo[trim((string) $detalle->codemedicina)] ?? null;

                return (object) [
                    'codigo' => trim((string) $detalle->codemedicina),
                    'nombre' => $this->nombreDe($delCatalogo, CamposDePaciente::texto($detalle->descripcion), trim((string) $detalle->codemedicina)),
                    'cantidad' => $detalle->cantidad !== null && (int) $detalle->cantidad > 0 ? (int) $detalle->cantidad : 1,
                    // La indicación del tratamiento manda; si no trae, la del vademécum (`uso`).
                    'indicaciones' => CamposDePaciente::texto($detalle->indicaciones) ?? CamposDePaciente::texto($delCatalogo->uso ?? null),
                ];
            })->values(),
        ])->values();
    }

    /**
     * El medicamento del vademécum del médico por su código, o `null` si no está. Es la lectura que
     * decide si un código del formulario se puede **agregar**: lo que el buscador ofrece y lo que el POST
     * acepta no pueden divergir.
     */
    public function delVademecum(string $regMedico, ?string $codigo): ?object
    {
        $codigo = CamposDePaciente::texto($codigo);

        if ($codigo === null) {
            return null;
        }

        $fila = DB::table('vademecum')
            ->where('reg_medico', $regMedico)
            ->where('codemedicina', $codigo)
            ->first(['codemedicina', 'nombrecomercial', 'nombregenerico', 'presentacion', 'uso']);

        return $fila === null ? null : $this->medicamento($fila);
    }

    /**
     * ¿Ese código es de un medicamento de **un tratamiento** del médico? Un tratamiento puede traer un
     * código que el vademécum ya no tiene (el legado no impide borrarlo) y, como es el catálogo del propio
     * médico, el récipe lo acepta; el nombre lo da el tratamiento.
     */
    public function delTratamiento(string $regMedico, ?string $codigo): ?object
    {
        $codigo = CamposDePaciente::texto($codigo);

        if ($codigo === null) {
            return null;
        }

        $detalle = DB::table('recipe_grupo_detalle')
            ->where('reg_medico', $regMedico)
            ->where('codemedicina', $codigo)
            ->orderBy('id')
            ->first(['codemedicina', 'descripcion']);

        if ($detalle === null) {
            return null;
        }

        return (object) [
            'codigo' => trim((string) $detalle->codemedicina),
            'nombre' => CamposDePaciente::texto($detalle->descripcion) ?? trim((string) $detalle->codemedicina),
        ];
    }

    /**
     * Los medicamentos que llegaron del formulario de un récipe, **listos para `GuardarRecipe`** y
     * comprobados contra los catálogos del médico.
     *
     * Tres reglas, y las tres son de la web (el sync del móvil no las tiene y no cambia):
     *
     *  - **No hay texto libre**: `recipes.codemedicina` es obligatorio (`varchar(8)`), así que un
     *    medicamento **nuevo** en el récipe tiene que estar en el vademécum del médico o ser de uno de sus
     *    tratamientos. Uno que ya estaba en el récipe se conserva aunque el vademécum ya no lo tenga (el
     *    legado no impide borrarlo) y editarlo no pasa por el catálogo.
     *  - **La cantidad por defecto es 1** (el `golo = 1` del *Pasar* del escritorio, `:13355`).
     *  - **El nombre lo pone el servidor**: el `NOMBRE/presentación` del vademécum se copia en
     *    `recipes.descripcion` de la fila nueva, como el `INSERT` del escritorio (`descripcion = compute_0006`),
     *    para que el escritorio —que muestra el nombre cruzando con su vademécum— y cualquier consumidor que
     *    no cruce vean el mismo texto. El `nombre` que viaja en el formulario es sólo de pantalla y se ignora.
     *
     * @param  array<int,array<string,mixed>>  $items  los `items` del formulario
     * @return array{items:array<int,array{codemedicina:string, cantidad:int, indicaciones:?string, descripcion:?string, nombre:string}>, desconocidos:array<int,string>}
     */
    public function resolverItems(Consulta $consulta, ?int $numero, array $items): array
    {
        $regMedico = (string) $consulta->reg_medico;

        $actuales = [];
        if ($numero !== null) {
            foreach (DB::table('recipes')
                ->where('reg_medico', $regMedico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->whereRaw('COALESCE(recipe, 1) = ?', [$numero])
                ->get(['codemedicina', 'descripcion']) as $fila) {
                $actuales[trim((string) $fila->codemedicina)] = CamposDePaciente::texto($fila->descripcion);
            }
        }

        $codigos = [];
        foreach ($items as $item) {
            $codigo = is_array($item) ? trim((string) ($item['codemedicina'] ?? '')) : '';
            if ($codigo !== '') {
                $codigos[] = $codigo;
            }
        }
        $catalogo = $this->vademecumPorCodigo($regMedico, $codigos);

        $resueltos = [];
        $desconocidos = [];
        $vistos = [];

        foreach ($items as $item) {
            $codigo = is_array($item) ? trim((string) ($item['codemedicina'] ?? '')) : '';

            if ($codigo === '' || isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;

            $cantidad = $item['cantidad'] ?? null;
            $indicaciones = CamposDePaciente::texto($item['indicaciones'] ?? null);

            $fila = [
                'codemedicina' => $codigo,
                'cantidad' => is_numeric($cantidad) && (int) $cantidad > 0 ? (int) $cantidad : 1,
                'indicaciones' => $indicaciones,
                'descripcion' => null,
                'nombre' => $this->nombreDe($catalogo[$codigo] ?? null, $actuales[$codigo] ?? null, $codigo),
            ];

            if (! array_key_exists($codigo, $actuales)) {
                $delTratamiento = isset($catalogo[$codigo]) ? null : $this->delTratamiento($regMedico, $codigo);

                if (! isset($catalogo[$codigo]) && $delTratamiento === null) {
                    $desconocidos[] = $codigo;
                    continue;
                }

                $fila['nombre'] = isset($catalogo[$codigo]) ? $fila['nombre'] : $delTratamiento->nombre;
                $fila['descripcion'] = $fila['nombre'];
            }

            $resueltos[] = $fila;
        }

        return ['items' => $resueltos, 'desconocidos' => $desconocidos];
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return array<int,string> las palabras de la búsqueda, normalizadas (sin tildes ni mayúsculas) */
    private function palabras(string $buscar): array
    {
        $normalizada = BusquedaDePacientes::normalizar(trim($buscar));

        return array_values(array_filter(preg_split('/\s+/u', $normalizada) ?: [], fn ($palabra) => $palabra !== ''));
    }

    /** Los que **empiezan** con lo escrito van primero, y después el orden alfabético por nombre. */
    private function puntaje($fila, array $palabras): string
    {
        $nombre = BusquedaDePacientes::normalizar(
            CamposDePaciente::texto($fila->nombrecomercial) ?? CamposDePaciente::texto($fila->nombregenerico) ?? ''
        );
        $empieza = $palabras !== [] && str_starts_with($nombre, $palabras[0]) ? '0' : '1';

        return $empieza . $nombre . '|' . BusquedaDePacientes::normalizar((string) $fila->presentacion);
    }

    /** @return object{codigo:string, nombre:string, comercial:?string, generico:?string, presentacion:?string, uso:?string} */
    private function medicamento(object $fila): object
    {
        $codigo = trim((string) $fila->codemedicina);

        return (object) [
            'codigo' => $codigo,
            'nombre' => $this->nombreDe($fila, null, $codigo),
            'comercial' => CamposDePaciente::texto($fila->nombrecomercial),
            'generico' => CamposDePaciente::texto($fila->nombregenerico),
            'presentacion' => CamposDePaciente::texto($fila->presentacion),
            'uso' => CamposDePaciente::texto($fila->uso),
        ];
    }

    /**
     * El nombre como lo muestra el escritorio en la pestaña de récipes: **`NOMBRE/presentación`**
     * (`compute_0007 = nombrecomercial + '/' + presentacion`, `d_medicamentos_recipe_con.srd`). Si el
     * comercial falta, el genérico; si el medicamento ya no está en el vademécum, la `descripcion` que
     * guardó la fila (el escritorio la escribe con ese mismo texto, `:13351-13373`) y, por último, el código.
     */
    public function nombreDe(?object $medicamento, ?string $respaldo, string $codigo): string
    {
        $base = CamposDePaciente::texto($medicamento->nombrecomercial ?? null)
            ?? CamposDePaciente::texto($medicamento->nombregenerico ?? null);

        if ($base === null) {
            return $respaldo ?? 'Medicamento ' . $codigo;
        }

        $presentacion = CamposDePaciente::texto($medicamento->presentacion ?? null);

        return $presentacion === null ? $base : $base . '/' . $presentacion;
    }

    private function item(object $fila, ?object $medicamento): object
    {
        $codigo = trim((string) $fila->codemedicina);

        return (object) [
            'id' => (int) $fila->id,
            'codigo' => $codigo,
            'nombre' => $this->nombreDe($medicamento, CamposDePaciente::texto($fila->descripcion), $codigo),
            'generico' => CamposDePaciente::texto($medicamento->nombregenerico ?? null),
            'cantidad' => $fila->cantidad === null ? null : (int) $fila->cantidad,
            'indicaciones' => CamposDePaciente::texto($fila->indicaciones),
            'enVademecum' => $medicamento !== null,
        ];
    }

    /**
     * `código => fila` del vademécum del médico para los códigos pedidos. Si el vademécum trae un código
     * repetido gana el de nombre no vacío.
     *
     * @param  array<int,mixed>  $codigos
     * @return array<string,object>
     */
    private function vademecumPorCodigo(string $regMedico, array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter(
            array_map(fn ($codigo) => trim((string) $codigo), $codigos),
            fn ($codigo) => $codigo !== '',
        )));

        if ($codigos === []) {
            return [];
        }

        $indice = [];

        foreach (DB::table('vademecum')
            ->where('reg_medico', $regMedico)
            ->whereIn('codemedicina', $codigos)
            ->orderBy('id')
            ->get(['codemedicina', 'nombrecomercial', 'nombregenerico', 'presentacion', 'uso']) as $fila) {
            $codigo = trim((string) $fila->codemedicina);

            if (CamposDePaciente::texto($fila->nombrecomercial) !== null || CamposDePaciente::texto($fila->nombregenerico) !== null) {
                $indice[$codigo] ??= $fila;
            }
        }

        return $indice;
    }

    /**
     * La fecha de una columna `date` como `AAAA-MM-DD`. SQLite guarda las columnas `date` casteadas con
     * la hora (`2026-10-11 00:00:00`, trampa 12): se recorta, y una fecha vacía es `null`.
     */
    private function soloFecha($valor): ?string
    {
        $texto = CamposDePaciente::texto($valor);

        if ($texto === null || str_starts_with($texto, '0000')) {
            return null;
        }

        return substr($texto, 0, 10);
    }
}
