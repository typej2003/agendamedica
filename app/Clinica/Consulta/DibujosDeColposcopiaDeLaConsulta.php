<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\DibujoDeColposcopia;
use Illuminate\Support\Collection;

/**
 * **Los dibujos del cuello uterino de una consulta** (A-13): una fila por variante, con su trazo y su imagen base.
 *
 * El dato se llavea por `reg_medico` + `nrohistoria` + `nroconsulta` + `variante` —como `dibujos_de_mamas`— y esta
 * clase es la única que arma esa clave: la usan la pantalla (el ViewModel del lienzo) y la hoja impresa, así que las
 * dos ven exactamente los mismos dibujos.
 *
 * Es de sólo lectura y no toca el disco: el archivo lo resuelve quien lo sirve o lo incrusta.
 */
final class DibujosDeColposcopiaDeLaConsulta
{
    /**
     * Las filas de la consulta, por variante (las que no tienen fila no aparecen en la colección).
     *
     * @return Collection<string,DibujoDeColposcopia>
     */
    public function de(Consulta $consulta): Collection
    {
        return DibujoDeColposcopia::where([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
        ])->get()->keyBy('variante');
    }

    /**
     * El estado que pinta la pantalla, por variante: la base elegida (normalizada) y si hay trazo guardado.
     *
     * @return array<string,array{base:string, dibujo:bool}>
     */
    public function estado(Consulta $consulta): array
    {
        $filas = $this->de($consulta);
        $estado = [];

        foreach (DibujoDeColposcopia::VARIANTES as $variante) {
            $fila = $filas->get($variante);

            $estado[$variante] = [
                'base' => ImagenDeColposcopia::base($fila?->base),
                'dibujo' => $fila !== null && $fila->archivo !== null,
            ];
        }

        return $estado;
    }
}
