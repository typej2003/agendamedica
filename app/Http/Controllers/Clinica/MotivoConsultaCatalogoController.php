<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearMotivoEnElCatalogo;
use App\Actions\Catalogos\EditarMotivoDelCatalogo;
use App\Actions\Catalogos\QuitarMotivoDelCatalogo;
use App\Actions\Catalogos\ResultadoDeBaja;
use App\Actions\Catalogos\ResultadoDeEdicion;
use App\Clinica\Catalogos\CatalogoSimpleDelCatalogo;
use App\Clinica\Catalogos\DefinicionDeCatalogoSimple;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Model;

/**
 * **Motivos de consulta del catálogo** (WEB-8.1b): `motivos_consulta` del médico del contexto
 * (`/clinica/catalogos/motivos-consulta`). No son los **motivos de cita** (`motivo_cita`, con precio y la marca de citas
 * múltiples), que se administran en `/clinica/catalogos/motivos-cita` (WEB-8.1c). Todo el comportamiento es el de
 * `CatalogoSimpleController`; el alta es la del catálogo de la consulta (`AgregarMotivoAlCatalogo`, vía `CrearMotivoEnElCatalogo`).
 */
class MotivoConsultaCatalogoController extends CatalogoSimpleController
{
    public function __construct(
        CatalogoSimpleDelCatalogo $catalogo,
        private CrearMotivoEnElCatalogo $alta,
        private EditarMotivoDelCatalogo $edicion,
        private QuitarMotivoDelCatalogo $baja,
    ) {
        parent::__construct($catalogo);
    }

    protected function definicion(): DefinicionDeCatalogoSimple
    {
        return DefinicionDeCatalogoSimple::motivosDeConsulta();
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
