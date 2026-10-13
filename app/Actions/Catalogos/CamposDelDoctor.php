<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarDoctorAlCatalogo;
use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Clinica\Pacientes\CamposDePaciente;
use InvalidArgumentException;

/**
 * **Los campos de un médico referido como el ABM los escribe** (WEB-8.1b): la normalización y los topes que comparten
 * el alta y la edición, para que las dos escriban la misma forma de fila (PLAN-WEB.md, R3).
 *
 * ## De dónde salen los campos (`d_a_medicos.srd`, el DataWindow de `w_agregar_doctores`)
 *
 * Los que el DataWindow deja editar son: apellidos (30, mayúsculas), nombres (30, mayúsculas), clínica (60), dirección
 * (texto largo), teléfono (108), ciudad (30), nota (texto largo) y la especialidad (`codeespecial`, un
 * `dropdowndatawindow` sobre `especial`). El código (`cedula`) lo da el correlativo y **no se edita**: en el escritorio
 * el `em_1` se deshabilita al cargar la fila. Los topes son los de `AgregarDoctorAlCatalogo` (el alta rápida de la
 * pestaña *Referencias*), cuyas constantes se usan, no se copian.
 */
final class CamposDelDoctor
{
    /** Las columnas que el ABM escribe, en el orden en que se muestran. */
    public const COLUMNAS = ['apellidos', 'nombres', 'codeespecial', 'clinica', 'ciudad', 'telefono', 'direccion', 'nota'];

    /** Cómo se llama cada columna en los avisos ("cambió los apellidos y la clínica"). */
    public const ETIQUETAS = [
        'apellidos' => 'los apellidos',
        'nombres' => 'los nombres',
        'codeespecial' => 'la especialidad',
        'clinica' => 'la clínica',
        'ciudad' => 'la ciudad',
        'telefono' => 'el teléfono',
        'direccion' => 'la dirección',
        'nota' => 'la nota',
    ];

    /**
     * Los campos como se escriben.
     *
     * @param  array<string,mixed>  $datos  `columna => valor` tal como llegó
     * @return array<string,?string> `columna => valor` de **todas** las columnas del ABM
     *
     * @throws InvalidArgumentException si falta el nombre o la especialidad, o algo pasa del largo de su columna
     */
    public static function normalizar(array $datos): array
    {
        $apellidos = AgregarMedicamentoAlVademecum::opcional($datos['apellidos'] ?? null, AgregarDoctorAlCatalogo::LARGO_APELLIDOS, 'Los apellidos', true);
        $nombres = AgregarMedicamentoAlVademecum::opcional($datos['nombres'] ?? null, AgregarDoctorAlCatalogo::LARGO_NOMBRES, 'Los nombres', true);

        if ($apellidos === null && $nombres === null) {
            throw new InvalidArgumentException('Escribe el nombre del médico.');
        }

        $especialidad = CamposDePaciente::texto($datos['codeespecial'] ?? null);

        if ($especialidad === null) {
            throw new InvalidArgumentException('Elige la especialidad del médico.');
        }

        return [
            'apellidos' => $apellidos,
            'nombres' => $nombres,
            'codeespecial' => $especialidad,
            'clinica' => AgregarMedicamentoAlVademecum::opcional($datos['clinica'] ?? null, AgregarDoctorAlCatalogo::LARGO_CLINICA, 'La clínica', false),
            'ciudad' => AgregarMedicamentoAlVademecum::opcional($datos['ciudad'] ?? null, AgregarDoctorAlCatalogo::LARGO_CIUDAD, 'La ciudad', false),
            'telefono' => AgregarMedicamentoAlVademecum::opcional($datos['telefono'] ?? null, AgregarDoctorAlCatalogo::LARGO_TELEFONO, 'El teléfono', false),
            'direccion' => AgregarMedicamentoAlVademecum::opcional($datos['direccion'] ?? null, AgregarDoctorAlCatalogo::LARGO_DIRECCION, 'La dirección', false, false),
            'nota' => AgregarMedicamentoAlVademecum::opcional($datos['nota'] ?? null, AgregarDoctorAlCatalogo::LARGO_NOTA, 'La nota', false, false),
        ];
    }
}
