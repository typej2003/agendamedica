<?php

namespace Tests\Unit\Clinica;

use App\Actions\Consulta\ConsultaCerrada;
use App\Clinica\Consulta\CitaEncontrada;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use PHPUnit\Framework\TestCase;

/**
 * **El cierre de la consulta** (WEB-4.8) sin base de datos ni HTTP: lo que la pantalla ofrece y lo que
 * dicen los dos objetos de la acción (`Docs/Web/guia-arquitectura-web.md`).
 *
 * Tres cosas distintas, cada una con su motivo:
 *
 *  - **`ConsultaShellVista`**: si la acción primaria se ofrece y con qué aviso. Lo decide el
 *    **controlador** (la consulta de hoy y la cita terminable) y el ViewModel sólo lo muestra.
 *  - **`CitaEncontrada`**: la regla del borde —una cita movida por el escritorio no se marca—, que el
 *    shell y la Action tienen que ver igual.
 *  - **`ConsultaCerrada`**: los tres desenlaces del cierre, que son mensajes distintos porque dicen
 *    cosas distintas ("la cita quedó atendida" no es "no había cita que marcar").
 */
class ConsultaCierreVistaTest extends TestCase
{
    private function paciente(array $campos = []): object
    {
        return (object) array_merge([
            'id'          => 5619,
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'numhistoria' => '1001',
        ], $campos);
    }

    private function shell(array $extra = []): ConsultaShellVista
    {
        return new ConsultaShellVista(
            $extra['paciente'] ?? $this->paciente(),
            $extra['nroconsulta'] ?? 3,
            $extra['fecha'] ?? '2026-10-05',
            $extra['urlPaciente'] ?? '/clinica/pacientes/5619',
            $extra['urlHistoria'] ?? '/clinica/pacientes/5619/historia',
            $extra['urlVolver'] ?? '/clinica/agenda?vista=dia&fecha=2026-10-05&sede=todas',
            $extra['urlConsulta'] ?? '/clinica/consulta/42',
            $extra['seccion'] ?? null,
            $extra['urlTerminar'] ?? '/clinica/consulta/42/cerrar',
            $extra['terminable'] ?? true,
        );
    }

    public function test_la_accion_de_terminar_se_ofrece_con_su_url_y_su_aviso(): void
    {
        $vm = $this->shell();

        $this->assertSame('/clinica/consulta/42/cerrar', $vm->urlTerminar());

        // El aviso nombra al paciente y la consulta: la acción es irreversible y la pantalla lo dice.
        $aviso = $vm->avisoTerminar();
        $this->assertStringContainsString('consulta N.º 3', $aviso);
        $this->assertStringContainsString('Pérez, Ana', $aviso);
        $this->assertStringContainsString('atendida', $aviso);
    }

    public function test_la_accion_no_se_ofrece_cuando_la_consulta_no_es_terminable(): void
    {
        // El controlador la marca `false` cuando la consulta no es de hoy o su cita no es terminable: la
        // vista no decide esto por su cuenta.
        $vm = $this->shell(['terminable' => false]);

        $this->assertNull($vm->urlTerminar());
    }

    public function test_sin_la_url_de_la_accion_tampoco_se_ofrece(): void
    {
        $vm = new ConsultaShellVista(
            $this->paciente(), 3, '2026-10-05',
            null, null, null, '/clinica/consulta/42', null,
            null,
            true,
        );

        $this->assertNull($vm->urlTerminar());
    }

    public function test_una_cita_movida_por_el_escritorio_no_es_terminable(): void
    {
        $movida = new CitaEncontrada(151, true);
        $this->assertFalse($movida->terminable());

        $normal = new CitaEncontrada(151, false);
        $this->assertTrue($normal->terminable());
        $this->assertSame(151, $normal->id);
    }

    public function test_los_tres_desenlaces_del_cierre_dicen_cosas_distintas(): void
    {
        $atendida = ConsultaCerrada::atendida(151);
        $this->assertTrue($atendida->quedoAtendida());
        $this->assertSame(151, $atendida->citaId);
        $this->assertStringContainsString('cita quedó atendida', $atendida->mensaje('3'));

        $sinCita = ConsultaCerrada::sinCita();
        $this->assertFalse($sinCita->quedoAtendida());
        $this->assertNull($sinCita->citaId);
        $this->assertStringContainsString('No se encontró una cita', $sinCita->mensaje('3'));

        $movida = ConsultaCerrada::citaMovida(151);
        $this->assertFalse($movida->quedoAtendida());
        $this->assertSame(151, $movida->citaId);
        $this->assertStringContainsString('movido el escritorio', $movida->mensaje('3'));

        // Las tres nombran la consulta: es lo que el médico acaba de cerrar.
        foreach ([$atendida, $sinCita, $movida] as $resultado) {
            $this->assertStringContainsString('Consulta N.º 3', $resultado->mensaje('3'));
        }
    }
}
