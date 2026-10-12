<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Http\Controllers\Controller;

/**
 * **La zona "Catálogos"** (WEB-8.1): no es una pantalla, es la entrada. Lleva al primer catálogo que tiene pantalla
 * (hoy los medicamentos) y cada catálogo muestra el índice de los demás (`CatalogosDisponibles`), así que cuando
 * WEB-8.1b sume los suyos la entrada de la barra no cambia.
 */
class CatalogoController extends Controller
{
    public function indice()
    {
        return redirect(CatalogosDisponibles::urlDeEntrada() ?? route('clinica.inicio'));
    }
}
