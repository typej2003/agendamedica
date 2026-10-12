<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearTipoDeExamenEnElCatalogo;
use App\Actions\Catalogos\EditarTipoDeExamenDelCatalogo;
use App\Actions\Catalogos\QuitarTipoDeExamenDelCatalogo;
use App\Actions\Catalogos\ResultadoDeBaja;
use App\Actions\Catalogos\ResultadoDeEdicion;
use App\Clinica\Catalogos\CatalogoSimpleDelCatalogo;
use App\Clinica\Catalogos\DefinicionDeCatalogoSimple;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Model;

/**
 * **Tipos de examen del catálogo** (WEB-8.1b): `tipos_examenes` del médico del contexto (`/clinica/catalogos/tipos-examen`),
 * el `w_agregar_tipo_exameen` del escritorio. Todo el comportamiento es el de `CatalogoSimpleController`; quitar un tipo
 * no se permite mientras algún examen del catálogo cuelgue de él.
 */
class TipoExamenCatalogoController extends CatalogoSimpleController
{
    public function __construct(
        CatalogoSimpleDelCatalogo $catalogo,
        private CrearTipoDeExamenEnElCatalogo $alta,
        private EditarTipoDeExamenDelCatalogo $edicion,
        private QuitarTipoDeExamenDelCatalogo $baja,
    ) {
        parent::__construct($catalogo);
    }

    protected function definicion(): DefinicionDeCatalogoSimple
    {
        return DefinicionDeCatalogoSimple::tiposDeExamen();
    }

    protected function crear(Medico $medico, string $regMedico, array $registros, string $descripcion): array
    {
        return $this->alta->ejecutar($regMedico, $registros, $descripcion);
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
