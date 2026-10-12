<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Http\Controllers\Controller;

/**
 * **La zona "Catálogos"** (WEB-8.1): no es una pantalla, es la entrada. Lleva al primer catálogo que tiene pantalla
 * (hoy los medicamentos); los catálogos son submenús de "Catálogos" en la barra lateral
 * (`CatalogosDisponibles::submenu()`), así que sumar uno no toca esta entrada.
 */
class CatalogoController extends Controller
{
    public function indice()
    {
        return redirect(CatalogosDisponibles::urlDeEntrada() ?? route('clinica.inicio'));
    }
}
