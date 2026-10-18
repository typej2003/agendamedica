<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Quitar una fila del catálogo inicial** (WEB-12.8). Sólo filas sin dueño (`reg_medico` NULL); lo que un médico ya copió **no cambia**. Respeta las dependencias dentro
 * del catálogo inicial: **no se quita** un tipo de examen que algún examen inicial usa ni un medicamento que algún tratamiento inicial lleva (primero se cambia o quita
 * lo que lo usa), y al quitar un tratamiento se van también sus líneas.
 */
final class QuitarFilaDeCatalogoInicial
{
    /**
     * @return string  el nombre de la fila quitada
     *
     * @throws InvalidArgumentException si no es una fila inicial o otra fila inicial la usa
     */
    public function ejecutar(string $slug, int $id): string
    {
        $def = CatalogosIniciales::de($slug);

        return DB::transaction(function () use ($def, $slug, $id) {
            $fila = DB::table($def['tabla'])->whereNull('reg_medico')->where('id', $id)->lockForUpdate()->first();

            if ($fila === null) {
                throw new InvalidArgumentException('Esa fila no está en el catálogo inicial.');
            }

            $nombre = trim((string) $fila->{$def['etiqueta']}) ?: 'código ' . trim((string) $fila->{$def['codigo']});
            $codigo = $fila->{$def['codigo']};

            foreach (CatalogosIniciales::todos() as $otroSlug => $otro) {
                foreach ($otro['refs'] ?? [] as $columna => $destino) {
                    if ($destino === $slug && DB::table($otro['tabla'])->whereNull('reg_medico')->where($columna, $codigo)->exists()) {
                        throw new InvalidArgumentException('No se quitó «' . $nombre . '»: ' . mb_strtolower($otro['titulo']) . ' del catálogo inicial lo usan. Cámbialos o quítalos primero.');
                    }
                }
                foreach ($otro['hijos'] ?? [] as $hijo) {
                    foreach ($hijo['refs'] ?? [] as $columna => $destino) {
                        if ($destino === $slug && DB::table($hijo['tabla'])->whereNull('reg_medico')->where($columna, $codigo)->exists()) {
                            throw new InvalidArgumentException('No se quitó «' . $nombre . '»: ' . mb_strtolower($otro['titulo']) . ' del catálogo inicial lo llevan. Quítalo de ellos primero.');
                        }
                    }
                }
            }

            foreach ($def['hijos'] ?? [] as $hijo) {
                DB::table($hijo['tabla'])->whereNull('reg_medico')->where($hijo['enlace'], $codigo)->delete();
            }
            DB::table($def['tabla'])->whereNull('reg_medico')->where('id', $id)->delete();

            return $nombre;
        });
    }
}
