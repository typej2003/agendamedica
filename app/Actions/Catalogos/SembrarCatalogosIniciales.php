<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Clinica\Catalogos\Iniciales\EspecialidadesDelCatalogoInicial;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Clinica\Pacientes\BusquedaDePacientes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Sembrar el catálogo inicial por especialidad** (WEB-12.9): carga en las filas iniciales (`reg_medico` NULL) lo que trae el escritorio de cada especialidad, ya
 * extraído a `database/catalogos-iniciales/<slug de la especialidad>/<catalogo>.json` (lo arma `bridge/tools/preparar-catalogos-iniciales.py` en el repo de docs).
 *
 *  - **Una fila por identidad** (la de `CatalogosIniciales`: el nombre normalizado, y el tipo en los exámenes). Si dos especialidades traen el mismo medicamento,
 *    queda una sola fila con las dos marcas.
 *  - **General**: lo que aparece en `UMBRAL_GENERAL` especialidades o más queda sin marca (lo recibe cualquier médico). Con las cinco bases del escritorio, «en todas»
 *    no daba casi nada: medido el 2026-10-21, ningún medicamento con su presentación estaba en las cinco.
 *  - **Sólo agrega**: una fila que ya existe no se edita. Si ya tiene marcas se le suman las que falten; si es general (sin marcas) queda general. Las filas nuevas
 *    reciben el siguiente código inicial. Correrla dos veces no cambia nada.
 *  - El tipo de un examen viene con el código **de la base de origen** y se traduce al código inicial de la fila que quedó con esa identidad.
 *  - **La línea de un tratamiento no usa ningún código del escritorio** (2026-10-26): trae el nombre y la presentación de su medicamento —el texto que el
 *    escritorio copia al récipe— y se vincula a la fila inicial con **exactamente esa identidad**. El preparador (`preparar-catalogos-iniciales.py`) agrega al
 *    archivo de medicamentos el que falte, así que una línea siempre encuentra el suyo; si no, se omite y se informa (nunca se vincula a otro parecido).
 *  - Ojo: si el administrador **quitó** una fila inicial, sembrar otra vez la vuelve a crear. Por eso esto se corre a mano, no en cada despliegue.
 *  - **Reemplazar una especialidad** (`$reemplazar`, 2026-10-25: la base vacía de pediatría que mandó Alexander sustituye a la que se había sembrado): antes de sembrar,
 *    a cada fila inicial **marcada** con esa especialidad que su archivo ya no trae se le quita la marca; si era sólo de ella, la fila se quita (con sus hijos), salvo
 *    que otra fila inicial la use, que entonces se conserva y se informa. Un tratamiento sólo de ella se quita **aunque el archivo lo traiga**, para rehacerlo con
 *    las líneas del archivo (la siembra no toca las líneas de un tratamiento que ya existe). Las filas generales y las de otras especialidades no se tocan, y lo que un médico ya copió
 *    tampoco.
 */
final class SembrarCatalogosIniciales
{
    public const UMBRAL_GENERAL = 3;

    /** Las dependencias primero: el examen apunta a su tipo y la línea de un tratamiento a su medicamento. */
    private const ORDEN = ['tipos-examen', 'medicamentos', 'diagnosticos', 'motivos-consulta', 'motivos-cita', 'plantillas-texto', 'dietas', 'antecedentes', 'examenes', 'tratamientos'];

    /** @var array<string,array<string,array<string,string>>> `catalogo => especialidad => código de origen => código inicial` */
    private array $mapa = [];

    /** @var array<string,array<string,array<string,string>>> `catalogo => especialidad => código de origen => identidad` */
    private array $identidades = [];

    /** @var array<string,string> `identidad del medicamento => código inicial`, para las líneas de los tratamientos */
    private array $medicamentos = [];

    /**
     * @param  array<int,string>  $reemplazar  especialidades (slug) cuyo catálogo inicial pasa a ser exactamente el de su carpeta (ver arriba)
     * @return array{especialidades: array<string,int>, sin_especialidad: array<int,string>, catalogos: array<string,array{nuevas:int, generales:int, marcas:int, lineas:int}>, retiradas: array<string,array<string,array{quitadas:int, desmarcadas:int, conservadas:int}>>, omitidas: array<int,string>}
     */
    public function ejecutar(string $carpeta, bool $simular = false, array $reemplazar = []): array
    {
        if (! is_dir($carpeta)) {
            throw new InvalidArgumentException('No existe la carpeta ' . $carpeta . '.');
        }

        $this->mapa = $this->identidades = $this->medicamentos = [];
        $informe = ['especialidades' => [], 'sin_especialidad' => [], 'catalogos' => [], 'retiradas' => [], 'omitidas' => []];

        foreach (glob(rtrim($carpeta, '/\\') . '/*', GLOB_ONLYDIR) as $dir) {
            $slug = basename($dir);
            $id = DB::table('specialties')->where('slug', $slug)->value('id');
            if ($id === null) {
                $informe['sin_especialidad'][] = $slug;
            } else {
                $informe['especialidades'][$slug] = (int) $id;
            }
        }
        ksort($informe['especialidades']);

        foreach ($reemplazar as $slug) {
            if (! isset($informe['especialidades'][$slug])) {
                throw new InvalidArgumentException('No se puede reemplazar «' . $slug . '»: no hay una carpeta de esa especialidad o la especialidad no existe.');
            }
        }

        DB::beginTransaction();

        try {
            foreach ($reemplazar as $slug) {
                $informe['retiradas'][$slug] = $this->retirar($carpeta, $slug, $informe['especialidades'][$slug], $informe['omitidas']);
            }
            $this->mapa = $this->identidades = [];

            foreach (self::ORDEN as $catalogo) {
                $informe['catalogos'][$catalogo] = $this->sembrar($catalogo, $carpeta, $informe['especialidades'], $informe['omitidas']);
            }
            $simular ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $informe;
    }

    /**
     * @param  array<string,int>  $especialidades
     * @param  array<int,string>  $omitidas
     * @return array{nuevas:int, generales:int, marcas:int, lineas:int}
     */
    private function sembrar(string $catalogo, string $carpeta, array $especialidades, array &$omitidas): array
    {
        $def = CatalogosIniciales::de($catalogo);
        $cuenta = ['nuevas' => 0, 'generales' => 0, 'marcas' => 0, 'lineas' => 0];

        // Lo que ya hay en el catálogo inicial, por identidad.
        $existentes = [];
        $codigos = [];
        foreach (DB::table($def['tabla'])->whereNull('reg_medico')->orderBy('id')->get() as $fila) {
            $existentes[$this->identidadInicial($def, (array) $fila)] ??= $fila;
            $codigos[] = $fila->{$def['codigo']};
        }

        // Lo que traen las especialidades, agrupado por identidad.
        $grupos = [];
        foreach ($especialidades as $slug => $specialtyId) {
            foreach ($this->leer($carpeta, $slug, $catalogo) as $fila) {
                $fila = $this->normalizarFila($def, $catalogo, $fila);
                if ($fila === null) {
                    continue;
                }
                $clave = $this->identidadDeOrigen($def, $slug, $fila);
                $this->identidades[$catalogo][$slug][(string) ($fila['codigo_origen'] ?? '')] = $clave;
                $grupos[$clave]['fila'] ??= [$slug, $fila];
                $grupos[$clave]['especialidades'][$slug] = $specialtyId;
            }
        }

        foreach ($grupos as $clave => $grupo) {
            $general = count($grupo['especialidades']) >= self::UMBRAL_GENERAL;
            $existente = $existentes[$clave] ?? null;

            if ($existente === null) {
                [$slug, $fila] = $grupo['fila'];
                $datos = $this->datos($def, $catalogo, $slug, $fila, $omitidas);
                if ($datos === null) {
                    continue;
                }
                $codigo = $this->siguienteCodigo($codigos, $def);
                $codigos[] = $codigo;
                $ahora = now();
                $id = (int) DB::table($def['tabla'])->insertGetId($datos + [
                    'reg_medico' => null, $def['codigo'] => $codigo, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $existente = (object) ['id' => $id, $def['codigo'] => $codigo];
                $existentes[$clave] = $existente;
                $cuenta['nuevas']++;
                if ($general) {
                    $cuenta['generales']++;
                } else {
                    $cuenta['marcas'] += EspecialidadesDelCatalogoInicial::agregar($catalogo, $id, array_values($grupo['especialidades']));
                }

                if ($catalogo === 'tratamientos') {
                    $cuenta['lineas'] += $this->lineas($slug, $fila, $codigo, $omitidas);
                }
            } elseif (! $general && EspecialidadesDelCatalogoInicial::de($catalogo, [(int) $existente->id]) !== []) {
                $cuenta['marcas'] += EspecialidadesDelCatalogoInicial::agregar($catalogo, (int) $existente->id, array_values($grupo['especialidades']));
            }
        }

        if ($catalogo === 'medicamentos') {
            foreach ($existentes as $clave => $fila) {
                $this->medicamentos[$clave] = trim((string) $fila->{$def['codigo']});
            }
        }

        // Cada código de origen, al código inicial de la fila que quedó con su identidad.
        foreach ($this->identidades[$catalogo] ?? [] as $slug => $origenes) {
            foreach ($origenes as $origen => $clave) {
                if (isset($existentes[$clave])) {
                    $this->mapa[$catalogo][$slug][$origen] = trim((string) $existentes[$clave]->{$def['codigo']});
                }
            }
        }

        return $cuenta;
    }

    /**
     * Le quita a la especialidad lo que su archivo ya no trae (ver «Reemplazar una especialidad» arriba). Los catálogos van **al revés** del orden de siembra: primero lo
     * que usa (un tratamiento, un examen) y después lo usado (el medicamento, el tipo), para que lo que se quita no deje a nada apuntando.
     *
     * @param  array<int,string>  $omitidas
     * @return array<string,array{quitadas:int, desmarcadas:int, conservadas:int}>
     */
    private function retirar(string $carpeta, string $slug, int $specialtyId, array &$omitidas): array
    {
        // Las identidades de lo que trae el archivo, en el orden de siembra (la identidad de un examen lleva la de su tipo).
        $enArchivo = [];
        foreach (self::ORDEN as $catalogo) {
            $def = CatalogosIniciales::de($catalogo);
            foreach ($this->leer($carpeta, $slug, $catalogo) as $fila) {
                $fila = $this->normalizarFila($def, $catalogo, $fila);
                if ($fila === null) {
                    continue;
                }
                $clave = $this->identidadDeOrigen($def, $slug, $fila);
                $this->identidades[$catalogo][$slug][(string) ($fila['codigo_origen'] ?? '')] = $clave;
                $enArchivo[$catalogo][$clave] = true;
            }
        }

        $cuenta = [];
        foreach (array_reverse(self::ORDEN) as $catalogo) {
            $def = CatalogosIniciales::de($catalogo);
            $cuenta[$catalogo] = ['quitadas' => 0, 'desmarcadas' => 0, 'conservadas' => 0];
            $filas = DB::table($def['tabla'])->whereNull('reg_medico')->orderBy('id')->get()->all();
            $marcas = EspecialidadesDelCatalogoInicial::de($catalogo, array_map(fn ($f) => (int) $f->id, $filas));

            foreach ($filas as $fila) {
                $suyas = $marcas[(int) $fila->id] ?? [];
                $rehacer = isset($def['hijos']) && $suyas === [$specialtyId];
                if (! in_array($specialtyId, $suyas, true) || (! $rehacer && isset($enArchivo[$catalogo][$this->identidadInicial($def, (array) $fila)]))) {
                    continue;
                }

                if (count($suyas) > 1) {
                    EspecialidadesDelCatalogoInicial::asignar($catalogo, (int) $fila->id, array_values(array_diff($suyas, [$specialtyId])));
                    $cuenta[$catalogo]['desmarcadas']++;

                    continue;
                }

                try {
                    app(QuitarFilaDeCatalogoInicial::class)->ejecutar($catalogo, (int) $fila->id);
                    $cuenta[$catalogo]['quitadas']++;
                } catch (InvalidArgumentException) {
                    $omitidas[] = $def['titulo'] . ' (' . $slug . '): se conservó «' . trim((string) $fila->{$def['etiqueta']}) . '» porque otra fila inicial lo usa.';
                    $cuenta[$catalogo]['conservadas']++;
                }
            }
        }

        return $cuenta;
    }

    /** @return array<int,array<string,mixed>> */
    private function leer(string $carpeta, string $especialidad, string $catalogo): array
    {
        $ruta = rtrim($carpeta, '/\\') . '/' . $especialidad . '/' . $catalogo . '.json';
        if (! is_file($ruta)) {
            return [];
        }

        $filas = json_decode((string) file_get_contents($ruta), true);
        if (! is_array($filas)) {
            throw new InvalidArgumentException('No se pudo leer ' . $ruta . '.');
        }

        return $filas;
    }

    /** La fila con las reglas del formulario del administrador (mayúsculas, largo), o `null` si no se puede cargar. */
    private function normalizarFila(array $def, string $catalogo, array $fila): ?array
    {
        foreach ($def['campos'] as $columna => [, $tipo, $max, , $mayusculas]) {
            if (! isset($fila[$columna]) || ! in_array($tipo, ['texto', 'area'], true)) {
                continue;
            }
            $texto = $tipo === 'texto' ? trim((string) preg_replace('/\s+/u', ' ', (string) $fila[$columna])) : trim((string) $fila[$columna]);
            $texto = $mayusculas ? mb_strtoupper($texto) : $texto;
            $fila[$columna] = $max > 0 ? mb_substr($texto, 0, $max) : $texto;
        }

        foreach ($def['campos'] as $columna => [, $tipo]) {
            if ($tipo === 'opcion') {
                $fila[$columna] = trim((string) ($fila[$columna] ?? ''));
                if (! array_key_exists($fila[$columna], $def['opciones'][$columna] ?? [])) {
                    return null;
                }
            }
        }

        if ($catalogo === 'plantillas-texto') {
            $fila['tipo'] = strtoupper(trim((string) ($fila['tipo'] ?? '')));
            if (! TiposDePlantillaDeTexto::conocido($fila['tipo'])) {
                return null;
            }
        }

        return trim((string) ($fila[$def['etiqueta']] ?? '')) === '' ? null : $fila;
    }

    /** Las columnas que se guardan, con las referencias ya traducidas al código inicial (`null` si una referencia no está). */
    private function datos(array $def, string $catalogo, string $especialidad, array $fila, array &$omitidas): ?array
    {
        $datos = [];
        foreach ($def['columnas'] as $columna) {
            $datos[$columna] = $fila[$columna] ?? null;
        }

        foreach ($def['refs'] ?? [] as $columna => $destino) {
            $codigo = $this->mapa[$destino][$especialidad][(string) ($fila[$columna] ?? '')] ?? null;
            if ($codigo === null) {
                $omitidas[] = $def['titulo'] . ' (' . $especialidad . '): «' . $fila[$def['etiqueta']] . '» apunta a un código que su base no trae.';

                return null;
            }
            $datos[$columna] = $codigo;
        }

        return $datos;
    }

    private function lineas(string $especialidad, array $tratamiento, string $codigo, array &$omitidas): int
    {
        $n = 0;

        foreach ($tratamiento['lineas'] ?? [] as $i => $linea) {
            $medicamento = $this->medicamentoDeLaLinea($especialidad, $linea);
            if ($medicamento === null) {
                $omitidas[] = 'Tratamientos (' . $especialidad . '): la línea «' . trim((string) ($linea['descripcion'] ?? '')) . '» de «' . $tratamiento['tratamiento']
                    . '» no tiene en el catálogo un medicamento con ese nombre y esa presentación.';

                continue;
            }

            $ahora = now();
            DB::table('recipe_grupo_detalle')->insert([
                'reg_medico' => null, 'codigo' => $codigo, 'codemedicina' => $medicamento,
                'descripcion' => $linea['descripcion'] ?? null, 'indicaciones' => $linea['indicaciones'] ?? null,
                'cantidad' => $linea['cantidad'] ?? 1, 'orden' => (int) ($linea['orden'] ?? $i),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            $n++;
        }

        return $n;
    }

    /** El código inicial del medicamento con **exactamente** el nombre y la presentación de la línea (normalizados como el resto del catálogo), o `null`. */
    private function medicamentoDeLaLinea(string $especialidad, array $linea): ?string
    {
        $def = CatalogosIniciales::de('medicamentos');
        $fila = $this->normalizarFila($def, 'medicamentos', [
            'nombrecomercial' => $linea['nombrecomercial'] ?? '', 'presentacion' => $linea['presentacion'] ?? '',
        ]);

        return $fila === null ? null : ($this->medicamentos[$this->identidadDeOrigen($def, $especialidad, $fila)] ?? null);
    }

    /** La identidad de una fila del archivo: la referencia (el tipo de un examen) por la identidad de la fila a la que apunta **en su misma base**. */
    private function identidadDeOrigen(array $def, string $especialidad, array $fila): string
    {
        return collect($def['identidad'])->map(function ($parte) use ($def, $especialidad, $fila) {
            if (str_starts_with($parte, 'ref:')) {
                $columna = substr($parte, 4);

                return $this->identidades[$def['refs'][$columna]][$especialidad][(string) ($fila[$columna] ?? '')] ?? '?' . ($fila[$columna] ?? '');
            }

            return self::normalizar($fila[$parte] ?? '');
        })->implode('|');
    }

    /** La identidad de una fila inicial: la referencia, por la identidad de la fila inicial a la que apunta. */
    private function identidadInicial(array $def, array $fila): string
    {
        return collect($def['identidad'])->map(function ($parte) use ($def, $fila) {
            if (str_starts_with($parte, 'ref:')) {
                $columna = substr($parte, 4);
                $destino = CatalogosIniciales::de($def['refs'][$columna]);
                $apuntada = DB::table($destino['tabla'])->whereNull('reg_medico')->where($destino['codigo'], $fila[$columna] ?? '')->first();

                return $apuntada === null ? '?' . ($fila[$columna] ?? '') : $this->identidadInicial($destino, (array) $apuntada);
            }

            return self::normalizar($fila[$parte] ?? '');
        })->implode('|');
    }

    private static function normalizar($valor): string
    {
        return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $valor)));
    }

    private function siguienteCodigo(array $codigos, array $def): string
    {
        return ReglasDelCatalogo::siguienteCodigo($codigos, mb_strtolower($def['titulo']), $def['digitos']);
    }
}
