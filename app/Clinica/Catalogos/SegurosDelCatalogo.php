<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\SegEmp;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los seguros / empresas del consultorio para el ABM** (O-62): el catálogo `seg_emp` del `reg_medico` del contexto,
 * con el buscador sin tildes (nombre, RIF, dirección, teléfono o código) y paginado de 25.
 *
 * El buscador es en PHP, no un `LIKE` de SQL: el catálogo compara sin tildes ni mayúsculas (trampa 13) y los seguros
 * de un consultorio son decenas de filas, no miles.
 *
 * Sólo lectura: `encontrar` responde 404 para quien lo llama y las escrituras son las Actions del catálogo.
 */
final class SegurosDelCatalogo
{
    use PaginaUnaColeccion;

    /** @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda) */
    public function pagina(string $regMedico, string $buscar, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return SegEmp::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): SegEmp
    {
        return SegEmp::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /** @return Collection<int,SegEmp> */
    private function coincidentes(string $regMedico, string $buscar): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $nombre = fn (SegEmp $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->nombre)));

        return SegEmp::where('reg_medico', $regMedico)->get()
            ->filter(function (SegEmp $fila) use ($palabras, $nombre) {
                $pajar = $nombre($fila) . ' ' . BusquedaDePacientes::normalizar(implode(' ', array_filter([
                    CamposDePaciente::texto($fila->rif),
                    CamposDePaciente::texto($fila->direccion),
                    CamposDePaciente::texto($fila->telef),
                    // El código se busca como está (`03`) y sin los ceros de adelante (`3`).
                    trim((string) $fila->codesegemp),
                    (string) (int) $fila->codesegemp,
                ], fn ($valor) => $valor !== null && $valor !== '')));

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (SegEmp $fila) => ($nombre($fila) === '' ? '1' : '0') . $nombre($fila)
                . '|' . str_pad(trim((string) $fila->codesegemp), 8, '0', STR_PAD_LEFT))
            ->values();
    }
}
