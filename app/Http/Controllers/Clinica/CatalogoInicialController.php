<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CopiarCatalogoInicial;
use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **«Cargar el catálogo inicial»** de los catálogos del médico (WEB-12.8): `POST /clinica/catalogos/{catalogo}/cargar-inicial` copia al consultorio lo que el catálogo
 * inicial tiene y el médico no (`CopiarCatalogoInicial`), sin pisar nada suyo. Es del **médico** (el grupo de rutas lleva `role:Root|Medico`); la regla vive en la Action.
 */
class CatalogoInicialController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function cargar(Request $request, string $catalogo, CopiarCatalogoInicial $accion)
    {
        abort_unless(CatalogosIniciales::existe($catalogo), 404);

        $contexto = $request->attributes->get('contexto_clinico');
        $def = CatalogosIniciales::de($catalogo);
        $destino = redirect()->route(CatalogosDisponibles::rutaDe($catalogo) ?? 'clinica.inicio');

        $r = $accion->ejecutar($catalogo, (string) $contexto['reg_medico'], $this->registrosDelMedico($contexto['medico']));

        if ($r['copiadas'] === 0 && $r['dependencias'] === 0) {
            return $destino->with('estado', 'Ya tienes todo lo que trae el catálogo inicial de ' . mb_strtolower($def['titulo']) . ': no se agregó nada.')
                ->with('omitidas', $r['omitidas']);
        }

        $texto = 'Se cargaron ' . $r['copiadas'] . ' ' . ($r['copiadas'] === 1 ? $def['singular'] : mb_strtolower($def['titulo']))
            . ($r['dependencias'] > 0 ? ', más ' . $r['dependencias'] . ' de lo que dependen' : '')
            . ($r['hijas'] > 0 ? ' y ' . $r['hijas'] . ' líneas de detalle' : '')
            . ' del catálogo inicial. Lo que ya tenías no se tocó.'
            . ($r['omitidas'] !== [] ? ' No se copiaron ' . count($r['omitidas']) . ' por falta de una dependencia en el catálogo inicial.' : '');

        return $destino->with('estado', $texto);
    }
}
