<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Agenda\Jornada;
use App\Clinica\Agenda\LecturaDeAgenda;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\Vistas\InicioVista;
use App\Models\Cola;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * **El inicio del médico: las consultas de hoy** (A-12). Lo que decide el ViewModel, sin base de datos: el orden
 * (atendidas primero, y dentro de cada grupo por hora), las movidas al final, el resumen y el estado vacío.
 * Extiende `Tests\TestCase` porque la fila genera URLs con `route()`.
 */
class InicioVistaTest extends TestCase
{
    private function ahora(): Carbon
    {
        return Carbon::create(2026, 10, 17, 9, 0);
    }

    private function cita(array $campos = []): CitaDeAgenda
    {
        $datos = array_merge([
            'id' => 10, 'fecha' => '2026-10-17', 'horaIni' => '09:30:00', 'numOrden' => 3,
            'estado' => Cola::ESTADO_NO_CONFIRMADA, 'atendido' => 0, 'monto' => 30.0, 'montoPagado' => 0.0,
            'numHistoria' => 4001, 'pacienteSinHistoriaId' => null, 'pacienteId' => 77,
            'paciente' => 'Alvarez, Ana', 'centroId' => 1, 'motivo' => null, 'tipo' => 'CTRL', 'medico' => null,
            'movidaEscritorio' => false, 'motivoNombre' => 'Control', 'telefono' => '04141234567',
            'email' => 'ana@example.com',
        ], $campos);

        return new CitaDeAgenda(...$datos);
    }

    /** @param  array<int,CitaDeAgenda>  $citas */
    private function vista(array $citas, array $contexto = []): InicioVista
    {
        $jornadas = $citas === [] ? collect() : collect([
            new Jornada('2026-10-17', 1, 0, null, null, collect($citas)),
        ]);

        $lectura = new LecturaDeAgenda(
            parametros: new ParametrosDeAgenda('dia', Carbon::create(2026, 10, 17), null, null),
            sedes: collect(),
            razones: collect(),
            razon: null,
            jornadas: $jornadas,
            posiciones: [10 => 1, 11 => 2, 12 => 3, 13 => 4],
            conteosPorDia: collect(['2026-10-17' => count($citas)]),
            noLaborables: collect(),
            avisoNoLaborable: null,
            totalRango: count($citas),
            mensajes: [],
            ahora: $this->ahora(),
            pacientesSinHistoria: collect(),
        );

        return new InicioVista($lectura, array_merge([
            'medico' => (object) ['prefix' => 'Dr.', 'name' => 'Carlos', 'lastname' => 'Mendoza'],
            'office' => (object) ['office_number' => 'C1'],
        ], $contexto), 0, true);
    }

    public function test_las_atendidas_van_primero_y_dentro_de_cada_grupo_manda_la_hora(): void
    {
        $vista = $this->vista([
            $this->cita(['id' => 10, 'horaIni' => '08:00:00', 'paciente' => 'Pendiente, Temprano']),
            $this->cita(['id' => 11, 'horaIni' => '09:00:00', 'paciente' => 'Pendiente, Tarde']),
            $this->cita(['id' => 12, 'horaIni' => '11:00:00', 'atendido' => 1, 'paciente' => 'Atendida, Segunda']),
            $this->cita(['id' => 13, 'horaIni' => '10:00:00', 'atendido' => 1, 'paciente' => 'Atendida, Primera']),
        ]);

        $nombres = array_map(fn (array $item) => $item['fila']->paciente(), $vista->filas());

        $this->assertSame([
            'Atendida, Primera',
            'Atendida, Segunda',
            'Pendiente, Temprano',
            'Pendiente, Tarde',
        ], $nombres);
    }

    public function test_las_citas_movidas_por_el_escritorio_van_al_final(): void
    {
        $vista = $this->vista([
            $this->cita(['id' => 10, 'horaIni' => '08:00:00', 'paciente' => 'Pendiente, Temprano']),
            $this->cita(['id' => 11, 'horaIni' => '07:00:00', 'movidaEscritorio' => true, 'paciente' => 'Movida, Vieja']),
            $this->cita(['id' => 12, 'horaIni' => '12:00:00', 'atendido' => 1, 'paciente' => 'Atendida, Primera']),
        ]);

        $this->assertSame([
            'Atendida, Primera',
            'Pendiente, Temprano',
            'Movida, Vieja',
        ], array_map(fn (array $item) => $item['fila']->paciente(), $vista->filas()));
    }

    public function test_el_resumen_cuenta_las_atendidas_y_el_subtitulo_lleva_fecha_medico_y_sede(): void
    {
        $vista = $this->vista([
            $this->cita(['id' => 10, 'atendido' => 1]),
            $this->cita(['id' => 11]),
            $this->cita(['id' => 12]),
        ]);

        $this->assertSame('1 de 3 atendidos', $vista->resumen());
        $this->assertSame('Sábado 17/10/2026 · Dr. Carlos Mendoza · C1 · 1 de 3 atendidos', $vista->subtitulo());
        $this->assertFalse($vista->estaVacio());
    }

    public function test_sin_citas_el_inicio_esta_vacio_y_ofrece_las_dos_salidas(): void
    {
        $vista = $this->vista([]);

        $this->assertTrue($vista->estaVacio());
        $this->assertNull($vista->resumen());
        $this->assertSame('Hoy no hay pacientes', $vista->vacio()['titulo']);
        $this->assertStringContainsString('agenda', $vista->urlAgenda());
        $this->assertStringContainsString('agenda/nueva', $vista->urlNuevaCita());
        // El vacío ya no promete el alta del que llega sin cita: la acción primaria es agendar una cita.
        $this->assertStringContainsString('Crea una cita nueva', $vista->vacio()['texto']);
    }

    /**
     * **«Nueva cita»**: es el formulario de la agenda con el día del inicio ya puesto, así que no depende de la
     * lista; lo que cambia es sólo dónde vive el botón (el encabezado, siempre visible).
     */
    public function test_la_accion_primaria_es_nueva_cita_con_el_dia_del_inicio(): void
    {
        $conPacientes = $this->vista([$this->cita()]);
        $sinPacientes = $this->vista([]);

        foreach ([$conPacientes, $sinPacientes] as $vista) {
            $this->assertStringContainsString('agenda/nueva', $vista->urlNuevaCita());
            $this->assertStringContainsString('fecha=2026-10-17', $vista->urlNuevaCita());
            $this->assertStringContainsString('hoy', $vista->ayudaNuevaCita());
        }
    }
}
