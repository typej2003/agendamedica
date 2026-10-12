<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Consulta\DoctoresDelMedico;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Doctor;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los médicos referidos del médico para el ABM** (WEB-8.1b): su libreta de colegas (`doctores`) con el buscador sin
 * tildes (nombre, clínica, ciudad, teléfono, especialidad o código), ordenada por apellidos y paginada de a 25.
 *
 * Cada fila lleva `especialidad_nombre` (el de `especial`, o `null` si el código no está en el catálogo) y `repetidos`
 * (cuántas filas tienen el mismo nombre completo **y** la misma especialidad, sin tildes ni mayúsculas): se ven todas, el ABM
 * es donde se limpian. La lista es la del `reg_medico` del contexto; el nombre de la especialidad se resuelve contra
 * **todos los registros del médico** como el resto de la web (`DoctoresDelMedico`).
 *
 * Sólo lectura (`encontrar` responde 404 para quien lo llama).
 */
final class DoctoresDelCatalogo
{
    use PaginaUnaColeccion;

    public function __construct(private DoctoresDelMedico $doctores)
    {
    }

    /**
     * @param  array<int,string>  $registros  los registros del médico, para resolver la especialidad
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda)
     */
    public function pagina(string $regMedico, array $registros, string $buscar, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $registros, $buscar), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return Doctor::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): Doctor
    {
        return Doctor::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * Las especialidades que se ofrecen (`código => nombre`).
     *
     * @param  array<int,string>  $registros
     * @return array<string,string>
     */
    public function especialidades(array $registros): array
    {
        return $this->doctores->especialidades($registros);
    }

    /** @return Collection<int,Doctor> */
    private function coincidentes(string $regMedico, array $registros, string $buscar): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $especialidades = $this->especialidades($registros);
        $nombre = fn (Doctor $fila) => BusquedaDePacientes::normalizar(trim(trim((string) $fila->nombres) . ' ' . trim((string) $fila->apellidos)));
        $identidad = fn (Doctor $fila) => $nombre($fila) . '|' . trim((string) $fila->codeespecial);

        $todos = Doctor::where('reg_medico', $regMedico)->get();
        $repetidos = $todos->countBy(fn (Doctor $fila) => $identidad($fila));

        return $todos
            ->each(fn (Doctor $fila) => $fila->setAttribute('especialidad_nombre', $especialidades[trim((string) $fila->codeespecial)] ?? null))
            ->filter(function (Doctor $fila) use ($palabras, $nombre) {
                $pajar = $nombre($fila) . ' ' . BusquedaDePacientes::normalizar(implode(' ', array_filter([
                    CamposDePaciente::texto($fila->clinica),
                    CamposDePaciente::texto($fila->ciudad),
                    CamposDePaciente::texto($fila->telefono),
                    CamposDePaciente::texto($fila->especialidad_nombre),
                    (string) (int) $fila->cedula,
                ])));

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (Doctor $fila) => BusquedaDePacientes::normalizar(trim(trim((string) $fila->apellidos) . ' ' . trim((string) $fila->nombres)))
                . '|' . str_pad((string) (int) $fila->cedula, 12, '0', STR_PAD_LEFT))
            ->values()
            ->each(fn (Doctor $fila) => $fila->setAttribute('repetidos', (int) ($repetidos[$identidad($fila)] ?? 1)));
    }
}
