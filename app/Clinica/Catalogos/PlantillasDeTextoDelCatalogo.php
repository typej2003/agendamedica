<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\FormatoPrint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Las plantillas de texto del médico para el ABM** (WEB-8.4): `formato_print` del `reg_medico` del contexto, con el buscador sin
 * tildes (título, texto, código o nombre del tipo), el filtro por tipo y el paginado de 25.
 *
 * Se ven **todas**, también las que no tienen texto (la consulta no las ofrece, pero el ABM es donde se arreglan) y las de un tipo
 * que la web todavía no usa. Sólo lectura. Todo está acotado al `reg_medico` del contexto (`encontrar` responde 404 para quien lo
 * llama): la plantilla de otro médico no existe para la pantalla.
 */
final class PlantillasDeTextoDelCatalogo
{
    use PaginaUnaColeccion;

    /**
     * @param  string  $tipo  el código de un tipo o vacío (todos)
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda y el tipo)
     */
    public function pagina(string $regMedico, string $buscar, string $tipo, int $pagina, string $ruta, array $query = []): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar, $tipo), $pagina, $ruta, $query);
    }

    public function total(string $regMedico): int
    {
        return FormatoPrint::where('reg_medico', $regMedico)->count();
    }

    public function encontrar(string $regMedico, int $id): FormatoPrint
    {
        return FormatoPrint::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /**
     * Cuántas plantillas tiene el médico de cada tipo (`código => cantidad`), con los tipos que la web usa siempre presentes (aunque
     * sea en 0) y después los demás que tenga. Es el `<select>` del filtro.
     *
     * @return array<string,int>
     */
    public function tipos(string $regMedico): array
    {
        $cantidades = FormatoPrint::where('reg_medico', $regMedico)->get(['tipo'])->countBy(fn (FormatoPrint $fila) => trim((string) $fila->tipo));
        $tipos = [];

        foreach (TiposDePlantillaDeTexto::USADOS_EN_LA_WEB as $codigo) {
            $tipos[$codigo] = (int) ($cantidades[$codigo] ?? 0);
        }

        foreach ($cantidades as $codigo => $cantidad) {
            if ($codigo !== '' && ! isset($tipos[$codigo])) {
                $tipos[$codigo] = $cantidad;
            }
        }

        return $tipos;
    }

    /** @return Collection<int,FormatoPrint> */
    private function coincidentes(string $regMedico, string $buscar, string $tipo): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $titulo = fn (FormatoPrint $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->titulo)));

        return FormatoPrint::where('reg_medico', $regMedico)->get()
            ->filter(fn (FormatoPrint $fila) => $tipo === '' || trim((string) $fila->tipo) === $tipo)
            ->filter(function (FormatoPrint $fila) use ($palabras, $titulo) {
                $pajar = $titulo($fila) . ' ' . BusquedaDePacientes::normalizar(
                    trim((string) $fila->codigo) . ' ' . TiposDePlantillaDeTexto::nombre($fila->tipo) . ' ' . (string) $fila->descripcion
                );

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (FormatoPrint $fila) => ($titulo($fila) === '' ? '1' : '0') . $titulo($fila) . '|' . str_pad(trim((string) $fila->codigo), 8, '0', STR_PAD_LEFT))
            ->values();
    }
}
