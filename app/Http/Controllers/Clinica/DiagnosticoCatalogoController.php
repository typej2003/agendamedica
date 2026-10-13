<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearDiagnosticoEnElCatalogo;
use App\Actions\Catalogos\EditarDiagnosticoDelCatalogo;
use App\Actions\Catalogos\QuitarDiagnosticoDelCatalogo;
use App\Actions\Catalogos\ResultadoDeBaja;
use App\Actions\Catalogos\ResultadoDeEdicion;
use App\Clinica\Catalogos\CatalogoSimpleDelCatalogo;
use App\Clinica\Catalogos\DefinicionDeCatalogoSimple;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Model;

/**
 * **Diagnósticos del catálogo** (WEB-8.1b): `diagnosticos` del médico del contexto (`/clinica/catalogos/diagnosticos`). Todo
 * el comportamiento —listado, diálogo, 404 del médico ajeno— es el de `CatalogoSimpleController`; acá sólo se dice con qué
 * Actions: el alta es la del catálogo de la consulta (`AgregarDiagnosticoAlCatalogo`, vía `CrearDiagnosticoEnElCatalogo`).
 */
class DiagnosticoCatalogoController extends CatalogoSimpleController
{
    public function __construct(
        CatalogoSimpleDelCatalogo $catalogo,
        private CrearDiagnosticoEnElCatalogo $alta,
        private EditarDiagnosticoDelCatalogo $edicion,
        private QuitarDiagnosticoDelCatalogo $baja,
    ) {
        parent::__construct($catalogo);
    }

    protected function definicion(): DefinicionDeCatalogoSimple
    {
        return DefinicionDeCatalogoSimple::diagnosticos();
    }

    protected function crear(Medico $medico, string $regMedico, array $registros, string $descripcion): array
    {
        return $this->alta->ejecutar($medico, $registros, $descripcion);
    }

    protected function editar(Model $fila, array $registros, string $descripcion): ResultadoDeEdicion
    {
        return $this->edicion->ejecutar($fila, $registros, $descripcion);
    }

    protected function quitarFila(Model $fila, array $registros): ResultadoDeBaja
    {
        return $this->baja->ejecutar($fila, $registros);
    }
}
