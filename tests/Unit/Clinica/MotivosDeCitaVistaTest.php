<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Agenda\Vistas\MotivosDeCitaVista;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MotivoCita;
use App\Models\Office;
use Tests\TestCase;

/**
 * La pantalla "Motivos de cita" (WEB-2.17): qué muestra por fila —la razón, la sede, el precio y la marca
 * de "admite varias citas pendientes"— y qué acción ofrece para cambiarla. Sin base de datos; extiende
 * `Tests\TestCase` solo porque el ViewModel genera URLs con `route()`.
 */
class MotivosDeCitaVistaTest extends TestCase
{
    private function medico(array $campos = []): Medico
    {
        return (new Medico())->forceFill(array_merge([
            'id'       => 1,
            'prefix'   => 'Dr',
            'name'     => 'Carlos',
            'lastname' => 'Mendoza',
        ], $campos));
    }

    /** Un motivo del catálogo, con su sede cargada si se pide (como los lee `MotivosDeCita::delMedico`). */
    private function motivo(array $campos = [], ?string $sede = null): MotivoCita
    {
        $motivo = (new MotivoCita())->forceFill(array_merge([
            'id'            => 1,
            'codigo'        => 'ECO',
            'tipo_atencion' => 'Ecografía',
            'precio'        => 120.0,
            'office_id'     => null,
        ], $campos));

        if ($sede !== null) {
            $office = (new Office())->forceFill(['id' => $motivo->office_id]);
            $office->setRelation('medicalCenter', (new MedicalCenter())->forceFill(['name' => $sede]));
            $motivo->setRelation('office', $office);
        }

        return $motivo;
    }

    private function vista(array $motivos, array $camposDelMedico = []): MotivosDeCitaVista
    {
        return new MotivosDeCitaVista(collect($motivos), $this->medico($camposDelMedico));
    }

    public function test_el_subtitulo_nombra_al_medico_y_explica_la_regla(): void
    {
        $vista = $this->vista([$this->motivo()]);

        $this->assertSame('Motivos de cita', $vista->titulo());
        $this->assertStringContainsString('Dr. Carlos Mendoza', $vista->subtitulo());
        // La regla común y su excepción, que es lo que la pantalla administra.
        $this->assertStringContainsString('una sola cita pendiente por consultorio', $vista->subtitulo());
        $this->assertStringContainsString('masajistas, terapias', $vista->subtitulo());
    }

    public function test_cada_fila_trae_la_razon_la_sede_el_precio_y_la_marca(): void
    {
        $vista = $this->vista([
            $this->motivo(['id' => 10, 'codigo' => 'ECO', 'precio' => 120.0]),
            $this->motivo(['id' => 11, 'codigo' => 'MASA', 'tipo_atencion' => 'Masaje terapéutico', 'office_id' => 7, 'precio' => 90.5], 'San José'),
        ]);

        $filas = $vista->filas();

        $this->assertCount(2, $filas);
        $this->assertSame('Ecografía', $filas[0]['razon']);
        $this->assertSame('ECO', $filas[0]['codigo']);
        // Sin sede el motivo se ofrece en todas (el escritorio no conoce el concepto de sede).
        $this->assertSame('Todas las sedes', $filas[0]['sede']);
        $this->assertSame('120,00', $filas[0]['precio']);

        $this->assertSame('Masaje terapéutico', $filas[1]['razon']);
        $this->assertSame('San José', $filas[1]['sede']);
        $this->assertSame('90,50', $filas[1]['precio']);
    }

    public function test_sin_marca_la_fila_dice_una_sola_cita_y_ofrece_admitir_varias(): void
    {
        // `null` es lo que traen las filas que ya existían y las que sube el escritorio: la regla común.
        $sinMarca = $this->vista([$this->motivo()])->filas()[0];
        $conFalse = $this->vista([$this->motivo(['admite_varias_citas_pendientes' => false])])->filas()[0];

        foreach ([$sinMarca, $conFalse] as $fila) {
            $this->assertSame('Una sola cita', $fila['marca']['texto']);
            $this->assertSame('neutro', $fila['marca']['tono']);
            $this->assertSame('Admitir varias', $fila['accion']['texto']);
        }

        $this->assertStringContainsString(
            route('clinica.agenda.motivos.alternar', 1),
            $sinMarca['urlAlternar'],
        );
    }

    public function test_con_la_marca_puesta_la_fila_dice_varias_citas_y_ofrece_volver_atras(): void
    {
        $fila = $this->vista([$this->motivo(['admite_varias_citas_pendientes' => true])])->filas()[0];

        $this->assertSame('Varias citas', $fila['marca']['texto']);
        $this->assertSame('info', $fila['marca']['tono']);
        $this->assertSame('Volver a una sola', $fila['accion']['texto']);
        // La acción nombra el motivo: el botón no dice cuál de las filas cambia.
        $this->assertStringContainsString('Ecografía', $fila['accion']['etiqueta']);
    }

    public function test_el_estado_vacio_dice_que_los_trae_la_sincronizacion(): void
    {
        $vacia = $this->vista([]);

        $this->assertTrue($vacia->estaVacio());
        $this->assertSame('Todavía no hay motivos de cita.', $vacia->textoVacio());
        $this->assertStringContainsString('sincronización del escritorio', $vacia->ayudaVacio());
        $this->assertSame('0 motivos', $vacia->conteo());

        $una = $this->vista([$this->motivo()]);
        $this->assertFalse($una->estaVacio());
        $this->assertSame('1 motivo', $una->conteo());
        $this->assertSame('2 motivos', $this->vista([$this->motivo(), $this->motivo(['id' => 2])])->conteo());
    }

    public function test_la_vuelta_es_a_la_agenda_del_dia(): void
    {
        $this->assertStringContainsString('/clinica/agenda?', $this->vista([])->urlVolver());
    }
}
