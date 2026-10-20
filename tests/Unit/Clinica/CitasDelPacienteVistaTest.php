<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Pacientes\Vistas\CitasDelPacienteVista;
use App\Models\Cola;
use Tests\TestCase;

/**
 * La pantalla "Citas del paciente" (WEB-2.15): lo que decide mostrar y ofrecer por fila. Sin base de
 * datos; extiende `Tests\TestCase` solo porque los ViewModels generan URLs con `route()`.
 */
class CitasDelPacienteVistaTest extends TestCase
{
    private function paciente(array $campos = []): object
    {
        return (object) array_merge([
            'id'          => 7,
            'nombres'     => 'Ana María',
            'apellidos'   => 'Pérez',
            'cedula'      => '12345678',
            'numhistoria' => 1001,
        ], $campos);
    }

    private function cita(array $campos = []): CitaDeAgenda
    {
        $d = array_merge([
            'id' => 10, 'fecha' => '2026-10-05', 'horaIni' => '09:30:00', 'numOrden' => 3,
            'estado' => Cola::ESTADO_NO_CONFIRMADA, 'atendido' => 0, 'monto' => 30.0, 'montoPagado' => 0.0,
            'numHistoria' => 1001, 'pacienteSinHistoriaId' => null, 'pacienteId' => 7,
            'paciente' => 'Pérez, Ana María', 'centroId' => null, 'motivo' => null, 'tipo' => 'CTRL',
            'medico' => null, 'movidaEscritorio' => false, 'motivoNombre' => 'Control',
            'telefono' => null, 'email' => null, 'cedula' => '12345678',
        ], $campos);

        return new CitaDeAgenda(...$d);
    }

    private function vista(array $citas, array $campos = []): CitasDelPacienteVista
    {
        return new CitasDelPacienteVista(
            $this->paciente($campos),
            collect($citas),
        );
    }

    public function test_el_subtitulo_cuenta_las_citas_y_dice_la_historia(): void
    {
        $dos = $this->vista([$this->cita(), $this->cita(['id' => 11])]);

        $this->assertSame('Pérez, Ana María', $dos->nombre());
        $this->assertSame('1001', $dos->numeroDeHistoria());
        $this->assertSame('12345678', $dos->cedula());
        $this->assertStringContainsString('Historia N.º 1001', $dos->subtitulo());
        $this->assertStringContainsString('2 citas', $dos->subtitulo());
        $this->assertFalse($dos->estaVacio());

        $una = $this->vista([$this->cita()]);
        $this->assertStringContainsString('1 cita,', $una->subtitulo());
    }

    public function test_sin_citas_lo_dice_y_no_lo_deja_parecer_un_error(): void
    {
        $vacia = $this->vista([]);

        $this->assertTrue($vacia->estaVacio());
        $this->assertStringContainsString('No tiene citas registradas', $vacia->subtitulo());
        $this->assertSame('bi-calendar-x', $vacia->vacio()['icono']);
        $this->assertStringContainsString('Todavía no tiene citas', $vacia->vacio()['titulo']);
    }

    public function test_sin_numero_de_historia_no_se_puede_cambiar_la_cedula_y_dice_por_que(): void
    {
        $sinHistoria = $this->vista([], ['numhistoria' => null]);

        $this->assertNull($sinHistoria->numeroDeHistoria());
        $this->assertFalse($sinHistoria->puedeCambiarCedula());
        $this->assertStringContainsString('Todavía no tiene número de historia', $sinHistoria->motivoSinCambiarCedula());
        $this->assertStringContainsString('Todavía sin número de historia', $sinHistoria->subtitulo());

        $conHistoria = $this->vista([]);
        $this->assertTrue($conHistoria->puedeCambiarCedula());
        $this->assertNull($conHistoria->motivoSinCambiarCedula());
    }

    public function test_los_estados_de_la_cita_son_atendida_movida_confirmada_y_por_atender(): void
    {
        $vista = $this->vista([]);

        $filas = [
            'atendida'    => $vista->estado($this->fila(['atendido' => 1])),
            'movida'      => $vista->estado($this->fila(['movidaEscritorio' => true])),
            'confirmada'  => $vista->estado($this->fila(['estado' => Cola::ESTADO_CONFIRMADA])),
            'porAtender'  => $vista->estado($this->fila(['estado' => null])),
        ];

        $this->assertSame(['exito', 'Atendida'], [$filas['atendida']['tono'], $filas['atendida']['texto']]);
        $this->assertSame(['neutro', 'Movida'], [$filas['movida']['tono'], $filas['movida']['texto']]);
        $this->assertSame(['marca', 'Confirmada'], [$filas['confirmada']['tono'], $filas['confirmada']['texto']]);
        $this->assertSame(['neutro', 'Por atender'], [$filas['porAtender']['tono'], $filas['porAtender']['texto']]);

        // Una cita confirmada por el paciente también cuenta como confirmada (`Cola::ESTADO_*`).
        $porPaciente = $vista->estado($this->fila(['estado' => Cola::ESTADO_CONFIRMADA_PACIENTE]));
        $this->assertSame('Confirmada', $porPaciente['texto']);
    }

    public function test_la_fila_muestra_la_fecha_legible_y_no_inventa_un_turno(): void
    {
        $vista = $this->vista([]);
        $fila = $this->fila();

        $this->assertSame('lunes 05/10/2026', $vista->cuando($fila));
        $this->assertSame('9:30 AM', $fila->hora());
        $this->assertSame('', $fila->turno(), 'sin jornada a la vista no hay número de turno');
    }

    public function test_la_confirmacion_de_eliminar_nombra_la_cita(): void
    {
        $vista = $this->vista([]);

        $this->assertSame(
            '¿Eliminar la cita del lunes 05/10/2026 a las 9:30 AM? No se puede deshacer.',
            $vista->confirmarEliminar($this->fila())
        );
    }

    public function test_las_reglas_de_la_fila_son_las_de_la_agenda_y_explican_lo_que_no_se_ofrece(): void
    {
        $vista = $this->vista([]);

        // Pendiente, sin pago: se modifica y se elimina.
        $pendiente = $this->fila();
        $this->assertNotNull($pendiente->urlEditar());
        $this->assertNotNull($pendiente->urlEliminar());
        $this->assertNull($vista->motivoSinEliminar($pendiente));

        // Atendida: ya ocurrió (la consulta cuelga de la cita).
        $atendida = $this->fila(['atendido' => 1]);
        $this->assertNull($atendida->urlEditar());
        $this->assertNull($atendida->urlEliminar());
        $this->assertStringContainsString('Ya fue atendida', $vista->motivoSinEliminar($atendida));

        // Con un pago registrado no se borra, pero sí se cobra.
        $pagada = $this->fila(['montoPagado' => 30.0]);
        $this->assertNull($pagada->urlEliminar());
        $this->assertStringContainsString('pago registrado', $vista->motivoSinEliminar($pagada));

        // Movida por el escritorio: ninguna acción.
        $movida = $this->fila(['movidaEscritorio' => true]);
        $this->assertNull($movida->urlEditar());
        $this->assertNull($movida->urlEliminar());
        $this->assertStringContainsString('pasó a otro día', $vista->motivoSinEliminar($movida));
    }

    public function test_las_urls_apuntan_a_las_acciones_que_ya_existen(): void
    {
        $vista = $this->vista([]);

        $this->assertSame(route('clinica.pacientes.cedula', 7), $vista->urlCambiarCedula());
        $this->assertSame(route('clinica.pacientes.ver', 7), $vista->urlFicha());
        $this->assertSame(route('clinica.agenda.editar', 10), $vista->urlEditar(10));
        $this->assertSame(
            route('clinica.pacientes.citas.eliminar', ['paciente' => 7, 'cola' => 10]),
            $vista->urlEliminar(10)
        );
        $this->assertSame(route('clinica.pacientes'), $vista->urlPacientes());
    }

    /** La fila de la vista con la cita pedida: es lo que la pantalla recorre. */
    private function fila(array $campos = [])
    {
        return $this->vista([$this->cita($campos)])->filas()[0];
    }
}
