<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Sync\RegistroDeCambios;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Copiar un catálogo inicial al consultorio** (WEB-12.8), para cualquiera de los catálogos que declara `CatalogosIniciales` (los de las vacunas tienen su propia
 * Action, `CopiarVacunasIniciales`, porque sus códigos no se renumeran). Cada médico tiene su propia configuración y parte de una copia de lo que arma el
 * administrador: las filas con `reg_medico` NULL.
 *
 *  - Copia **sólo lo que el médico no tiene**: una fila inicial cuya identidad (el nombre normalizado, sin tildes ni mayúsculas; ver `identidad` en el registro) ya está
 *    en el catálogo del médico —aunque la haya editado— no es una novedad y **no se toca**. Es **idempotente**: una segunda vez no copia nada.
 *  - **Los códigos los da el servidor**: la fila copiada recibe el siguiente código del médico (el mayor numérico de **todos sus registros** + 1), nunca el inicial.
 *  - **Respeta las dependencias**: un examen apunta a su tipo y el medicamento de un tratamiento a su vademécum; esas referencias se **mapean por identidad** al catálogo
 *    del médico (si el médico ya tiene un tipo con ese nombre, se usa el suyo; si no, se copia el tipo) y los hijos (el detalle de un tratamiento) se copian con su
 *    padre. Una fila cuya dependencia no existe en el catálogo inicial **se omite** y se informa, no se copia a medias.
 *  - Cada fila copiada anota su `created` en `sync_changes` con `source = web`: es lo que impide que la siguiente subida del escritorio, que reemplaza las tablas
 *    sin clave (`sin_clave_con_altas_del_app`: tratamientos, motivos de cita, plantillas), borre lo que se copió.
 *  - Nunca modifica ni borra filas iniciales ni de otro consultorio.
 */
final class CopiarCatalogoInicial
{
    /** @var array<string,Collection> */
    private array $memo = [];

    /**
     * Las filas iniciales que el médico todavía no tiene (las **novedades**), una por identidad.
     *
     * @param  array<int,string>  $registros  todos los `reg_medico` del médico (el correlativo y la identidad se miden sobre ellos)
     * @return Collection<int,object>
     */
    public function novedades(string $slug, array $registros): Collection
    {
        $this->memo = [];
        $def = CatalogosIniciales::de($slug);
        $propias = $this->clavesDelMedico($def, $registros);

        return $this->iniciales($def)
            ->unique(fn ($fila) => $this->clave($def, $fila, true, $registros))
            ->reject(fn ($fila) => isset($propias[$this->clave($def, $fila, true, $registros)]))
            ->values();
    }

    /**
     * @param  array<int,string>  $registros
     * @return array{copiadas:int, dependencias:int, hijas:int, omitidas:array<int,string>}
     */
    public function ejecutar(string $slug, string $regMedico, array $registros): array
    {
        $registros = array_values(array_unique(array_merge($registros, [$regMedico])));
        $resultado = ['copiadas' => 0, 'dependencias' => 0, 'hijas' => 0, 'omitidas' => []];

        return DB::transaction(function () use ($slug, $regMedico, $registros, &$resultado) {
            foreach ($this->novedades($slug, $registros) as $fila) {
                $this->memo = [];
                $this->insertar(CatalogosIniciales::de($slug), $fila, $regMedico, $registros, $resultado, false);
            }

            return $resultado;
        });
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return int|string|null el código nuevo de la fila, o `null` si se omitió */
    private function insertar(array $def, object $fila, string $regMedico, array $registros, array &$resultado, bool $esDependencia): ?string
    {
        $datos = [];
        foreach ($def['columnas'] as $columna) {
            $datos[$columna] = $fila->{$columna} ?? null;
        }

        foreach ($def['refs'] ?? [] as $columna => $destino) {
            $codigo = $this->mapear($destino, (string) ($fila->{$columna} ?? ''), $regMedico, $registros, $resultado);

            if ($codigo === null) {
                $resultado['omitidas'][] = $def['titulo'] . ': «' . trim((string) $fila->{$def['etiqueta']}) . '» apunta a algo que no está en el catálogo inicial.';

                return null;
            }
            $datos[$columna] = $codigo;
        }

        $codigo = $this->siguienteCodigo($def, $registros);
        $ahora = now();

        $id = (int) DB::table($def['tabla'])->insertGetId($datos + [
            'reg_medico' => $regMedico, $def['codigo'] => $codigo, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        RegistroDeCambios::registrarOperacion($def['tabla'], $id, $regMedico, 'created', null, null, $ahora, 'web');
        $esDependencia ? $resultado['dependencias']++ : $resultado['copiadas']++;

        foreach ($def['hijos'] ?? [] as $hijo) {
            $this->copiarHijos($hijo, $fila->{$def['codigo']}, $codigo, $regMedico, $registros, $resultado);
        }

        return $codigo;
    }

    private function copiarHijos(array $hijo, $codigoInicial, string $codigoNuevo, string $regMedico, array $registros, array &$resultado): void
    {
        $filas = DB::table($hijo['tabla'])->whereNull('reg_medico')->where($hijo['enlace'], $codigoInicial)
            ->orderBy($hijo['orden'] ?? 'id')->orderBy('id')->get();

        foreach ($filas as $h) {
            $datos = [];
            foreach ($hijo['columnas'] as $columna) {
                $datos[$columna] = $h->{$columna} ?? null;
            }

            foreach ($hijo['refs'] ?? [] as $columna => $destino) {
                $codigo = $this->mapear($destino, (string) ($h->{$columna} ?? ''), $regMedico, $registros, $resultado);

                if ($codigo === null) {
                    $resultado['omitidas'][] = 'Una línea del tratamiento (código ' . trim((string) $codigoInicial) . ') apunta a un medicamento que no está en el catálogo inicial.';
                    continue 2;
                }
                $datos[$columna] = $codigo;
            }

            $ahora = now();
            $id = (int) DB::table($hijo['tabla'])->insertGetId($datos + [
                'reg_medico' => $regMedico, $hijo['enlace'] => $codigoNuevo, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            RegistroDeCambios::registrarOperacion($hijo['tabla'], $id, $regMedico, 'created', null, null, $ahora, 'web');
            $resultado['hijas']++;
        }
    }

    /** El código **del médico** de lo que el inicial citaba por su código: el de la fila que tenga esa identidad o, si falta, el de la copia que se hace ahora. */
    private function mapear(string $slugDestino, string $codigoInicial, string $regMedico, array $registros, array &$resultado): ?string
    {
        $def = CatalogosIniciales::de($slugDestino);
        $origen = $this->iniciales($def)->first(fn ($f) => trim((string) $f->{$def['codigo']}) === trim($codigoInicial));

        if ($origen === null) {
            return null;
        }

        $clave = $this->clave($def, $origen, true, $registros);
        $existente = $this->clavesDelMedico($def, $registros)[$clave] ?? null;

        if ($existente !== null) {
            return $existente;
        }

        $this->memo = [];

        return $this->insertar($def, $origen, $regMedico, $registros, $resultado, true);
    }

    /** @return Collection<int,object> las filas sin dueño de la tabla del catálogo, en el orden en que se cargaron */
    private function iniciales(array $def): Collection
    {
        return DB::table($def['tabla'])->whereNull('reg_medico')->orderBy('id')->get();
    }

    /** @return array<string,string> `identidad => código` de lo que el médico ya tiene */
    private function clavesDelMedico(array $def, array $registros): array
    {
        $claves = [];

        foreach (DB::table($def['tabla'])->whereIn('reg_medico', $registros)->orderBy('id')->get() as $fila) {
            $claves[$this->clave($def, $fila, false, $registros)] ??= trim((string) $fila->{$def['codigo']});
        }

        return $claves;
    }

    /** La identidad de una fila: sus columnas normalizadas (y la identidad de la fila a la que apunta, buscada en **su** ámbito: el inicial o el del médico). */
    private function clave(array $def, object $fila, bool $inicial, array $registros): string
    {
        $partes = [];

        foreach ($def['identidad'] as $parte) {
            if (str_starts_with($parte, 'ref:')) {
                $columna = substr($parte, 4);
                $destino = CatalogosIniciales::de($def['refs'][$columna]);
                $apuntada = $this->filasDe($destino, $inicial, $registros)->first(fn ($f) => trim((string) $f->{$destino['codigo']}) === trim((string) $fila->{$columna}));
                $partes[] = $apuntada === null ? '?' . trim((string) $fila->{$columna}) : $this->clave($destino, $apuntada, $inicial, $registros);

                continue;
            }

            $partes[] = BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) ($fila->{$parte} ?? ''))));
        }

        return implode('|', $partes);
    }

    /** @return Collection<int,object> */
    private function filasDe(array $def, bool $inicial, array $registros): Collection
    {
        $llave = $def['tabla'] . ($inicial ? ':i' : ':' . implode(',', $registros));

        return $this->memo[$llave] ??= $inicial
            ? $this->iniciales($def)
            : DB::table($def['tabla'])->whereIn('reg_medico', $registros)->get();
    }

    private function siguienteCodigo(array $def, array $registros): string
    {
        return ReglasDelCatalogo::siguienteCodigo(
            DB::table($def['tabla'])->whereIn('reg_medico', $registros)->pluck($def['codigo']),
            mb_strtolower($def['titulo']),
            $def['digitos'],
        );
    }
}
