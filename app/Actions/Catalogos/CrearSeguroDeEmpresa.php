<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\SegEmp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un seguro / empresa del consultorio** (O-62): el «Nuevo seguro» del catálogo del médico.
 *
 * La regla no está acá: el nombre obligatorio en mayúsculas, los opcionales y el tope de cada columna son de
 * `ReglasDelSeguroDeEmpresa`, y el código lo da el servidor con el correlativo del legado (dos dígitos). La fila
 * queda con el `reg_medico` del contexto —cada consultorio tiene su catálogo— y su rastro `created` en
 * `sync_changes` (`source = web`), como los otros catálogos.
 *
 * Lo que se carga acá es lo que ofrece el `<select>` «Seguro / empresa» del alta del paciente de **ese**
 * consultorio (`App\Clinica\Pacientes\CatalogoDeSeguros`).
 */
final class CrearSeguroDeEmpresa
{
    use EscribeConRastro;

    /**
     * @param  string  $regMedico  el registro del contexto: el consultorio dueño de la fila
     * @param  array<string,mixed>  $datos  `nombre`, `rif`, `direccion` y `telef` del formulario
     *
     * @throws InvalidArgumentException si falta el nombre, algo pasa del ancho de su columna, ya hay uno con ese
     *                                  nombre o el catálogo está lleno (99)
     */
    public function ejecutar(
        string $regMedico,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): SegEmp {
        $campos = ReglasDelSeguroDeEmpresa::campos($datos);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $campos, $cuando, $origen) {
            ReglasDelSeguroDeEmpresa::noDuplicar($regMedico, $campos['nombre']);

            return $this->crearFila(SegEmp::class, 'seg_emp', array_merge($campos, [
                'reg_medico' => $regMedico,
                'codesegemp' => ReglasDelSeguroDeEmpresa::siguienteCodigo(
                    SegEmp::where('reg_medico', $regMedico)->pluck('codesegemp')
                ),
            ]), $cuando, $origen);
        });
    }
}
