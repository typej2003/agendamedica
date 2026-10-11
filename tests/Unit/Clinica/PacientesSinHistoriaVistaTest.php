<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Agenda\Vistas\PacientesSinHistoriaVista;
use App\Models\Cola;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * El panel **"Pacientes sin historia a atender"** de la vista de día (WEB-2.11): qué lista, cómo lo
 * cuenta y a dónde apunta su única acción. Sin base de datos; extiende `Tests\TestCase` solo porque los
 * ViewModels generan URLs con `route()`.
 */
class PacientesSinHistoriaVistaTest extends TestCase
{
    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lunes = Carbon::parse('2026-10-05');
    }

    /** Una cita de un paciente sin historia, como la deja el alta de la web (anclada por el pivote). */
    private function cita(array $campos = []): CitaDeAgenda
    {
        $d = array_merge([
            'id' => 10, 'fecha' => '2026-10-05', 'horaIni' => '09:30:00', 'numOrden' => 1,
            'estado' => Cola::ESTADO_NO_CONFIRMADA, 'atendido' => 0, 'monto' => null, 'montoPagado' => null,
            'numHistoria' => null, 'pacienteSinHistoriaId' => 77, 'pacienteId' => 77,
            'paciente' => 'Alvarez, Ana', 'centroId' => 1, 'motivo' => null, 'tipo' => 'CTRL',
            'medico' => null, 'movidaEscritorio' => false, 'motivoNombre' => 'Control',
            'telefono' => null, 'email' => null, 'cedula' => null,
        ], $campos);

        return new CitaDeAgenda(...$d);
    }

    private function vista(array $citas): PacientesSinHistoriaVista
    {
        return new PacientesSinHistoriaVista(collect($citas), $this->lunes->copy());
    }

    public function test_el_encabezado_dice_el_dia_y_para_que_sirve(): void
    {
        $vista = $this->vista([$this->cita()]);

        $this->assertSame('Pacientes sin historia a atender', $vista->titulo());
        $this->assertStringContainsString('lunes 05/10/2026', $vista->subtitulo());
        $this->assertStringContainsString('Pasar a cita formal', $vista->subtitulo());
    }

    public function test_cuenta_los_pacientes_del_dia(): void
    {
        $this->assertSame('Ninguno', $this->vista([])->conteo());
        $this->assertSame('1 paciente', $this->vista([$this->cita()])->conteo());
        $this->assertSame('2 pacientes', $this->vista([$this->cita(), $this->cita(['id' => 11])])->conteo());
    }

    public function test_vacio_lo_dice_sin_parecer_un_error(): void
    {
        // El panel se ve siempre: vacío es la buena noticia del día, no un fallo.
        $vacia = $this->vista([]);

        $this->assertTrue($vacia->estaVacio());
        $this->assertSame([], $vacia->filas());
        $this->assertSame('bi-journal-medical', $vacia->vacio()['icono']);
        $this->assertStringContainsString('Hoy no hay pacientes sin historia', $vacia->vacio()['titulo']);
        $this->assertStringContainsString('número de historia', $vacia->vacio()['texto']);
    }

    /** Viendo otro día, el vacío no puede decir "Hoy": el jueves pasado decía "Hoy no hay…" (revisión 2026-10-11). */
    public function test_el_vacio_de_otro_dia_no_dice_hoy(): void
    {
        $otroDia = new PacientesSinHistoriaVista(collect([]), $this->lunes->copy(), false);

        $this->assertSame('Ese día no hay pacientes sin historia', $otroDia->vacio()['titulo']);
        $this->assertSame('Hoy no hay pacientes sin historia', $this->vista([])->vacio()['titulo'], 'Por defecto es hoy.');
    }

    public function test_cada_fila_dice_el_paciente_la_hora_y_su_accion(): void
    {
        $fila = $this->vista([$this->cita()])->filas()[0];

        $this->assertSame('Alvarez, Ana', $fila->paciente());
        $this->assertSame('09:30', $fila->hora());
        $this->assertSame('Control', $fila->razon());
        $this->assertSame(route('clinica.pacientes.ver', 77), $fila->urlPaciente());
        $this->assertSame(route('clinica.agenda.pasar-a-cita-formal', 10), $fila->urlPasarACitaFormal());
    }

    public function test_la_confirmacion_nombra_al_paciente_y_lo_que_va_a_pasar(): void
    {
        // El efecto es permanente y el número no se puede adelantar: la confirmación explica el efecto en
        // lugar de prometer un número que el servidor todavía no calculó.
        $confirmacion = $this->vista([$this->cita()])->filas()[0]->confirmar();

        $this->assertStringContainsString('Alvarez, Ana', $confirmacion);
        $this->assertStringContainsString('número de historia', $confirmacion);
        $this->assertStringContainsString('la cita queda enlazada', $confirmacion);
    }

    public function test_sin_ficha_no_se_enlaza_al_paciente_pero_la_accion_sigue(): void
    {
        // Un paciente borrado de `pacientes` deja la cita sin a quién enlazar; la Action lo va a rechazar.
        $fila = $this->vista([$this->cita(['pacienteId' => null, 'paciente' => 'Paciente sin ficha'])])->filas()[0];

        $this->assertNull($fila->urlPaciente());
        $this->assertSame(route('clinica.agenda.pasar-a-cita-formal', 10), $fila->urlPasarACitaFormal());
    }

    public function test_la_razon_cae_al_codigo_cuando_no_esta_en_el_catalogo(): void
    {
        $fila = $this->vista([$this->cita(['motivoNombre' => null, 'tipo' => 'ECO', 'motivo' => null])])->filas()[0];

        $this->assertSame('ECO', $fila->razon());
    }
}
