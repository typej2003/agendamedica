<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Examen;
use App\Models\TipoExamen;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los exámenes del médico para el ABM** (WEB-8.1b): `examenes` con su tipo (`tipos_examenes`), el buscador sin tildes
 * (nombre, código o tipo), el filtro por tipo —el `ddplb_estu` de `w_agregar_examenes_estudios`— y el paginado de 25.
 *
 * Cada fila lleva `tipo_nombre` (el nombre del tipo, o `null` si no tiene o su código no está en el catálogo) y
 * `repetidos` (cuántas filas tienen el mismo nombre **y** el mismo tipo, sin tildes ni mayúsculas). Se ven todos, repetidos
 * incluidos: el ABM es donde se limpian.
 *
 * Sólo lectura. Todo está acotado al `reg_medico` del contexto (`encontrar` responde 404 para quien lo llama).
 */
final class ExamenesDelCatalogo
{
    use PaginaUnaColeccion;

    /** El valor del filtro que deja sólo los exámenes sin tipo. */
    public const SIN_TIPO = 'sin-tipo';

    /**
     * @param  string  $tipo  el código de un tipo, `SIN_TIPO` o vacío (todos)
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda y el tipo)
     */
    public function pagina(string $regMedico, string $buscar, string $tipo, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar, $tipo), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return Examen::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): Examen
    {
        return Examen::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * Los tipos del médico: `código => nombre`, por nombre. Es el `<select>` del formulario y del filtro.
     *
     * @return array<string,string>
     */
    public function tipos(string $regMedico): array
    {
        $tipos = [];

        foreach (TipoExamen::where('reg_medico', $regMedico)->orderBy('tipo')->orderBy('codetipo')->get(['codetipo', 'tipo']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codetipo);

            if ($codigo !== null) {
                $tipos[$codigo] ??= CamposDePaciente::texto($fila->tipo) ?? ('Tipo ' . $codigo);
            }
        }

        return $tipos;
    }

    /** @return Collection<int,Examen> */
    private function coincidentes(string $regMedico, string $buscar, string $tipo): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $tipos = $this->tipos($regMedico);
        $nombre = fn (Examen $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->examen)));
        $codigoDelTipo = fn (Examen $fila) => CamposDePaciente::texto($fila->codetipo);

        $todos = Examen::where('reg_medico', $regMedico)->get();
        $repetidos = $todos->countBy(fn (Examen $fila) => $nombre($fila) . '|' . $codigoDelTipo($fila));

        return $todos
            ->each(fn (Examen $fila) => $fila->setAttribute('tipo_nombre', ($codigo = $codigoDelTipo($fila)) === null ? null : ($tipos[$codigo] ?? null)))
            ->filter(function (Examen $fila) use ($tipo, $codigoDelTipo) {
                if ($tipo === '') {
                    return true;
                }

                return $tipo === self::SIN_TIPO ? $codigoDelTipo($fila) === null : $codigoDelTipo($fila) === $tipo;
            })
            ->filter(function (Examen $fila) use ($palabras, $nombre) {
                $pajar = $nombre($fila) . ' ' . BusquedaDePacientes::normalizar(trim((string) $fila->codeexamen) . ' ' . (string) $fila->tipo_nombre);

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (Examen $fila) => ($nombre($fila) === '' ? '1' : '0') . $nombre($fila) . '|' . str_pad(trim((string) $fila->codeexamen), 8, '0', STR_PAD_LEFT))
            ->values()
            ->each(fn (Examen $fila) => $fila->setAttribute('repetidos', (int) ($repetidos[$nombre($fila) . '|' . $codigoDelTipo($fila)] ?? 1)));
    }
}
