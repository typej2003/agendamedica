<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Dieta;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Las dietas del médico para el ABM** (WEB-12.4b): `dietas` del `reg_medico` del contexto, con el buscador sin tildes (título, texto o código) y el
 * paginado de 25. Se ven **todas**, también las que no tienen texto (la consulta no las ofrece, pero el ABM es donde se arreglan). Sólo lectura; lo que
 * la consulta ofrece lo lee `Consulta\DietasDelCatalogo`. `encontrar` responde 404 para quien lo llama: la dieta de otro médico no existe.
 */
final class DietasDelCatalogoAbm
{
    use PaginaUnaColeccion;

    /** @param  array<string,mixed>  $query  los parámetros que el paginador conserva */
    public function pagina(string $regMedico, string $buscar, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return Dieta::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): Dieta
    {
        return Dieta::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /** @return Collection<int,Dieta> */
    private function coincidentes(string $regMedico, string $buscar): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $titulo = fn (Dieta $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->dietas)));

        return Dieta::where('reg_medico', $regMedico)->get()
            ->filter(function (Dieta $fila) use ($palabras, $titulo) {
                $pajar = $titulo($fila) . ' ' . BusquedaDePacientes::normalizar(trim((string) $fila->cod_dieta) . ' ' . (string) $fila->descripcion);

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (Dieta $fila) => ($titulo($fila) === '' ? '1' : '0') . $titulo($fila) . '|' . str_pad(trim((string) $fila->cod_dieta), 4, '0', STR_PAD_LEFT))
            ->values();
    }
}
