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
 * Se ven **todas las de la especialidad del contexto**, también las que no tienen texto (la consulta no las ofrece, pero el ABM es
 * donde se arreglan) y las de un tipo que la web todavía no usa. Las de **otra especialidad** no se ven: en pediatría no se ofrecen
 * las plantillas de colposcopía ni las de los ecos ginecológicos (ver `PlantillasDeLaEspecialidad`), y un tipo del legado que no
 * está en el catálogo **nunca** se oculta. Sólo lectura. Todo está acotado al `reg_medico` del contexto (`encontrar` responde 404
 * para quien lo llama): la plantilla de otro médico no existe para la pantalla.
 */
final class PlantillasDeTextoDelCatalogo
{
    use PaginaUnaColeccion;

    /**
     * @param  string  $tipo  el código de un tipo o vacío (todos)
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda y el tipo)
     */
    public function pagina(string $regMedico, string $buscar, string $tipo, int $pagina, string $ruta, array $query = [], ?string $especialidad = null): LengthAwarePaginator
    {
        return $this->paginar($this->coincidentes($regMedico, $buscar, $tipo, $especialidad), $pagina, $ruta, $query);
    }

    /** Cuántas plantillas ve el médico en esta especialidad (el subtítulo de la pantalla). */
    public function total(string $regMedico, ?string $especialidad = null): int
    {
        return FormatoPrint::where('reg_medico', $regMedico)->whereNull('formato')->get(['tipo'])
            ->reject(fn (FormatoPrint $fila) => PlantillasDeLaEspecialidad::estaOculto($fila->tipo, $especialidad))
            ->count();
    }

    public function encontrar(string $regMedico, int $id): FormatoPrint
    {
        // Una plantilla del **formato** (O-17) no es una plantilla de texto: por acá da 404, igual que la de
        // otro médico.
        return FormatoPrint::where('reg_medico', $regMedico)->whereNull('formato')->whereKey($id)->firstOrFail();
    }

    /**
     * Cuántas plantillas tiene el médico de cada tipo (`código => cantidad`), con los tipos que la web usa siempre presentes (aunque
     * sea en 0) y después los demás que tenga. Es el `<select>` del filtro, acotado a los tipos de la especialidad.
     *
     * @return array<string,int>
     */
    public function tipos(string $regMedico, ?string $especialidad = null): array
    {
        // Sólo las plantillas **de texto**: las del formato del eco (O-17) viven en la misma tabla y no son
        // de este catálogo (no tienen `tipo`).
        $cantidades = FormatoPrint::where('reg_medico', $regMedico)->whereNull('formato')->get(['tipo'])->countBy(fn (FormatoPrint $fila) => trim((string) $fila->tipo));
        $tipos = [];

        foreach (TiposDePlantillaDeTexto::USADOS_EN_LA_WEB as $codigo) {
            if (PlantillasDeLaEspecialidad::estaOculto($codigo, $especialidad)) {
                continue;
            }

            $tipos[$codigo] = (int) ($cantidades[$codigo] ?? 0);
        }

        // Y después los demás que el médico tenga —los formatos del escritorio que la web no ofrece y los tipos del legado—,
        // salvo los de otra especialidad, que no son de esta pantalla.
        foreach ($cantidades as $codigo => $cantidad) {
            if ($codigo !== '' && ! isset($tipos[$codigo]) && ! PlantillasDeLaEspecialidad::estaOculto($codigo, $especialidad)) {
                $tipos[$codigo] = $cantidad;
            }
        }

        return $tipos;
    }

    /** @return Collection<int,FormatoPrint> */
    private function coincidentes(string $regMedico, string $buscar, string $tipo, ?string $especialidad = null): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $titulo = fn (FormatoPrint $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->titulo)));

        return FormatoPrint::where('reg_medico', $regMedico)->whereNull('formato')->get()
            // Las de otra especialidad no son de esta pantalla (en pediatría, ni la colposcopía ni los ecos ginecológicos).
            ->reject(fn (FormatoPrint $fila) => PlantillasDeLaEspecialidad::estaOculto($fila->tipo, $especialidad))
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
