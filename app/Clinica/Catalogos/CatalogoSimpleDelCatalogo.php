<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **La lectura de un catálogo de "código + texto" para el ABM** (WEB-8.1b): diagnósticos, motivos de consulta y tipos de
 * examen del médico del contexto, con el buscador sin tildes (por el texto o por el código), ordenados por texto y paginados.
 *
 * El buscador de la consulta junta los repetidos en uno solo (para elegir, `DiagnosticosDeLaConsulta`,
 * `MotivosDeLaConsulta`) y **acá se ven todos**: el legado trae códigos y nombres repetidos y el ABM es justamente donde
 * se limpian. Cada fila lleva `repetidos`: cuántas filas del catálogo tienen el mismo nombre (sin tildes ni mayúsculas),
 * para que la pantalla lo avise.
 *
 * Sólo lectura. Todo está acotado al `reg_medico` del contexto: una fila de otro médico no existe para la pantalla
 * (`encontrar` responde 404 para quien lo llama).
 */
final class CatalogoSimpleDelCatalogo
{
    use PaginaUnaColeccion;

    /**
     * @param  string  $ruta  la URL de la pantalla, para los enlaces del paginador
     * @param  array<string,mixed>  $query  los parámetros que el paginador conserva (la búsqueda)
     */
    public function pagina(
        DefinicionDeCatalogoSimple $def,
        string $regMedico,
        string $buscar,
        int $pagina,
        string $ruta,
        array $query = [],
    ): LengthAwarePaginator {
        return $this->paginar($this->coincidentes($def, $regMedico, $buscar), $pagina, $ruta, $query);
    }

    /** Cuántas filas tiene el catálogo del médico (sin búsqueda): decide el estado vacío. */
    public function total(DefinicionDeCatalogoSimple $def, string $regMedico): int
    {
        $modelo = $def->modelo;

        return $modelo::where('reg_medico', $regMedico)->count();
    }

    /** Una fila del médico del contexto; la de otro médico no se toca (404 para quien llama). */
    public function encontrar(DefinicionDeCatalogoSimple $def, string $regMedico, int $id): Model
    {
        $modelo = $def->modelo;

        return $modelo::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    /** @return Collection<int,Model> */
    private function coincidentes(DefinicionDeCatalogoSimple $def, string $regMedico, string $buscar): Collection
    {
        $modelo = $def->modelo;
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));
        $texto = fn (Model $fila) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->getAttribute($def->columnaDelTexto))));
        $codigo = fn (Model $fila) => trim((string) $fila->getAttribute($def->columnaDelCodigo));

        $todas = $modelo::where('reg_medico', $regMedico)->get();

        // Cuántas filas comparten el mismo nombre normalizado: el aviso de "repetido".
        $repetidos = $todas->countBy(fn (Model $fila) => $texto($fila));

        return $todas
            ->filter(function (Model $fila) use ($palabras, $texto, $codigo) {
                $pajar = $texto($fila) . ' ' . BusquedaDePacientes::normalizar($codigo($fila));

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (Model $fila) => ($texto($fila) === '' ? '1' : '0') . $texto($fila) . '|' . str_pad($codigo($fila), 8, '0', STR_PAD_LEFT))
            ->values()
            ->each(fn (Model $fila) => $fila->setAttribute('repetidos', (int) ($repetidos[$texto($fila)] ?? 1)));
    }
}
