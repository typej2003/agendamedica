<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Pacientes\DatosObligatoriosDelPaciente;
use PHPUnit\Framework\TestCase;

class DatosObligatoriosDelPacienteTest extends TestCase
{
    private function paciente(array $datos = []): object
    {
        return (object) array_merge([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => '12345678', 'fnacimiento' => '1990-05-04', 'telefono' => '04141234567',
        ], $datos);
    }

    public function test_un_paciente_completo_no_tiene_faltantes(): void
    {
        $this->assertSame([], DatosObligatoriosDelPaciente::faltantes($this->paciente(), 'ginecologia-y-obstetricia'));
    }

    public function test_por_defecto_se_pide_nombre_nacimiento_y_telefono(): void
    {
        $faltan = DatosObligatoriosDelPaciente::faltantes($this->paciente(['nombres' => '', 'fnacimiento' => null, 'telefono' => 'none']), 'ginecologia-y-obstetricia');

        $this->assertSame(['nombre y apellido', 'fecha de nacimiento', 'teléfono'], $faltan);
    }

    public function test_en_pediatria_no_hay_cedula_y_el_telefono_es_el_del_representante(): void
    {
        $paciente = $this->paciente(['cedula' => null, 'telefono' => null]);

        $this->assertSame(['teléfono del representante'], DatosObligatoriosDelPaciente::faltantes($paciente, 'pediatria'));
        $this->assertSame([], DatosObligatoriosDelPaciente::faltantes($paciente, 'pediatria', '04241112233'));
    }
}
