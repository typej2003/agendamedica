<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consultorio\Vistas\SedesYConsultoriosVista;
use App\Models\MedicalCenter;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **Sedes y consultorios** (2026-10-20) sin base de datos: qué sedes van a la vista, el orden de los bloques, la sede
 * que el consultorio nuevo trae elegida y el panel que se abre al cargar.
 *
 * Desde el 2026-10-26 las sedes que recibe el ViewModel **ya son las del médico** (las filtra `SedesDeLaClinica`): acá
 * no hay catálogo de otros ni «otras sedes» que plegar, y la tarjeta dice si la puede administrar o sólo ver.
 *
 * ⚠️ Extiende `Tests\TestCase` (y no el de PHPUnit) porque el ViewModel lee `old()` y arma rutas: necesita la app
 * arrancada aunque no toque ninguna tabla (trampa 68).
 */
class SedesYConsultoriosVistaTest extends TestCase
{
    private function sede(int $id, string $nombre, bool $activa = true): MedicalCenter
    {
        $sede = new MedicalCenter(['name' => $nombre, 'address' => 'Av. ' . $nombre, 'activo' => $activa]);
        $sede->id = $id;

        return $sede;
    }

    private function consultorio(int $id, int $sede, string $numero, array $bloques = []): Office
    {
        $consultorio = new Office([
            'medical_center_id' => $sede,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
        $consultorio->id = $id;
        $consultorio->setRelation('schedules', new Collection($bloques));

        return $consultorio;
    }

    private function bloque(int $id, int $dia, string $inicio, string $fin, ?int $cupo = null): OfficeSchedule
    {
        $bloque = new OfficeSchedule(['dia_semana' => $dia, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'cupo' => $cupo]);
        $bloque->id = $id;

        return $bloque;
    }

    private function vista(array $sedes, ?array $consultorios, array $extra = []): SedesYConsultoriosVista
    {
        return new SedesYConsultoriosVista(
            new Collection($sedes),
            $consultorios === null ? null : new Collection($consultorios),
            new Collection(),
            false,
            null,
            null,
            $extra['panel'] ?? null,
            $extra['en'] ?? null,
            $extra['creada'] ?? null,
            count(array_filter($sedes, fn ($sede) => $sede->activo)),
            // Por defecto, todas administrables: las que llegan son las del médico.
            $extra['administrables'] ?? array_map(fn (MedicalCenter $sede) => $sede->id, $sedes),
        );
    }

    public function test_las_sedes_del_medico_son_las_tarjetas_y_cada_una_lista_sus_consultorios(): void
    {
        $vm = $this->vista(
            [$this->sede(1, 'Alfa'), $this->sede(2, 'Beta'), $this->sede(3, 'Gamma')],
            [$this->consultorio(10, 2, 'B-1')],
        );

        $this->assertSame(['Alfa', 'Beta', 'Gamma'], array_column($vm->tarjetas(), 'nombre'));
        $this->assertSame([], $vm->tarjetas()[0]['consultorios']);
        $this->assertSame(['B-1'], array_column($vm->tarjetas()[1]['consultorios'], 'nombre'));
        $this->assertTrue($vm->tarjetas()[0]['administrable']);
    }

    public function test_sin_contexto_no_hay_sedes_y_no_se_ofrece_crear_nada(): void
    {
        // El controlador no le pasa sedes sin contexto: la pantalla queda en el aviso.
        $sinContexto = $this->vista([], null);

        $this->assertSame([], $sinContexto->tarjetas());
        $this->assertTrue($sinContexto->estaVacio());
        $this->assertFalse($sinContexto->puedeCargarConsultorios());
        $this->assertFalse($sinContexto->puedeCrearSede());
        $this->assertNotNull($sinContexto->avisoSinContexto());
    }

    public function test_la_sede_que_solo_se_ve_por_consultorio_no_se_administra(): void
    {
        $vm = $this->vista(
            [$this->sede(1, 'Suya'), $this->sede(2, 'Prestada')],
            [],
            ['administrables' => [1]],
        );

        $this->assertTrue($vm->tarjetas()[0]['administrable']);
        $this->assertFalse($vm->tarjetas()[1]['administrable']);
    }

    public function test_la_sede_recien_creada_se_ve_y_dispara_la_pregunta(): void
    {
        $vm = $this->vista(
            [$this->sede(1, 'Alfa'), $this->sede(2, 'Nueva')],
            [$this->consultorio(10, 1, 'A-1')],
            ['creada' => 2],
        );

        $this->assertSame(['Alfa', 'Nueva'], array_column($vm->tarjetas(), 'nombre'));
        $this->assertSame('sede-creada', $vm->panelAlCargar());
        $this->assertSame('Nueva', $vm->preguntaSedeCreada()['nombre']);

        // Sin médico en el contexto no hay consultorio que ofrecer.
        $this->assertNull($this->vista([$this->sede(2, 'Nueva')], null, ['creada' => 2])->preguntaSedeCreada());
    }

    public function test_los_bloques_van_en_el_orden_de_la_semana(): void
    {
        $vm = $this->vista([$this->sede(1, 'Alfa')], [$this->consultorio(10, 1, 'A-1', [
            $this->bloque(1, 3, '14:00', '18:00', 12),
            $this->bloque(2, 1, '08:00', '12:00'),
        ])]);

        $bloques = $vm->tarjetas()[0]['consultorios'][0]['bloques'];

        $this->assertSame(['Lu', 'Mi'], array_column($bloques, 'dia'));
        $this->assertSame(['sin tope', '12 pacientes'], array_column($bloques, 'cupo'));
        $this->assertStringContainsString('Lunes', $bloques[0]['confirmar']);
    }

    public function test_el_consultorio_nuevo_trae_elegida_la_sede_pedida_o_la_unica(): void
    {
        $dos = [$this->sede(1, 'Alfa'), $this->sede(2, 'Beta'), $this->sede(3, 'Cerrada', false)];

        $elegidas = fn (SedesYConsultoriosVista $vm) => array_column(array_filter($vm->opcionesDeSede(), fn ($o) => $o['elegida']), 'texto');

        // Las inactivas no se ofrecen.
        $this->assertSame(['Alfa', 'Beta'], array_column($this->vista($dos, [])->opcionesDeSede(), 'texto'));
        $this->assertSame([], $elegidas($this->vista($dos, [])));
        $this->assertSame(['Beta'], $elegidas($this->vista($dos, [], ['en' => 2, 'panel' => 'consultorio'])));
        $this->assertSame(['Alfa'], $elegidas($this->vista([$this->sede(1, 'Alfa')], [])));
    }

    public function test_el_panel_que_pide_la_url(): void
    {
        $sedes = [$this->sede(1, 'Alfa')];

        $this->assertNull($this->vista($sedes, [])->panelAlCargar());
        $this->assertSame('sede', $this->vista($sedes, [], ['panel' => 'sede'])->panelAlCargar());
        $this->assertSame('consultorio', $this->vista($sedes, [], ['panel' => 'consultorio'])->panelAlCargar());
        // Sin contexto el panel del consultorio no existe.
        $this->assertNull($this->vista($sedes, null, ['panel' => 'consultorio'])->panelAlCargar());
    }
}
