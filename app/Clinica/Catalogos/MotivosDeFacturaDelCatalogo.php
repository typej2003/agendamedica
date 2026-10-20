<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\MotivoFactura;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los conceptos facturables del médico para el ABM** (`motivo_factura`, F9): buscador sin tildes (nombre o código) y
 * paginado de 25. Sólo lectura, acotado al `reg_medico` del contexto (`encontrar` responde 404 para quien lo llama).
 */
final class MotivosDeFacturaDelCatalogo
{
    use PaginaUnaColeccion;

    /** @param  array<string,mixed>  $query  los parámetros que el paginador conserva */
    public function pagina(string $regMedico, string $buscar, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return MotivoFactura::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): MotivoFactura
    {
        return MotivoFactura::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /** @return Collection<int,MotivoFactura> */
    private function coincidentes(string $regMedico, string $buscar): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $nombre = fn (MotivoFactura $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->descripcion)));

        return MotivoFactura::where('reg_medico', $regMedico)->get()
            ->filter(function (MotivoFactura $fila) use ($palabras, $nombre) {
                $pajar = $nombre($fila) . ' ' . BusquedaDePacientes::normalizar(trim((string) $fila->codigo));

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (MotivoFactura $fila) => ($nombre($fila) === '' ? '1' : '0') . $nombre($fila) . '|' . str_pad(trim((string) $fila->codigo), 8, '0', STR_PAD_LEFT))
            ->values();
    }
}
