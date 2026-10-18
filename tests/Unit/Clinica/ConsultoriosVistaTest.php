<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consultorio\Vistas\ConsultoriosVista;
use App\Models\Medico;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **Los consultorios del médico con sus bloques de horario en la misma fila** (A-10, revisión de Alexander),
 * sin base de datos: el orden de los bloques, el cupo opcional, el alta en línea y la ayuda de la duración.
 *
 * ⚠️ Extiende `Tests\TestCase` y no `PHPUnit\Framework\TestCase` como los otros ViewModels porque la pantalla
 * usa `ConValoresDeFormulario`, que arma el `old()` de un POST fallido: eso necesita la petición de Laravel
 * enlazada aunque no haya base de datos. Sin `DatabaseTransactions` no se toca ninguna tabla (todo lo que el
 * ViewModel recibe llega por el constructor).
 */
class ConsultoriosVistaTest extends TestCase
{
    private function consultorio(string $sede, string $numero, array $bloques = [], ?int $duracion = 30): Office
    {
        $consultorio = new Office([
            'office_number' => $numero,
            'modalidad'     => Office::MODALIDAD_ORDEN,
            'duracion_cita' => $duracion,
            'activo'        => true,
        ]);
        $consultorio->id = 7;
        $consultorio->setRelation('medicalCenter', new \App\Models\MedicalCenter(['name' => $sede]));
        $consultorio->setRelation('schedules', new Collection($bloques));

        return $consultorio;
    }

    private function bloque(int $dia, string $inicio, string $fin, ?int $cupo = null): OfficeSchedule
    {
        $bloque = new OfficeSchedule([
            'dia_semana'  => $dia,
            'hora_inicio' => $inicio,
            'hora_fin'    => $fin,
            'cupo'        => $cupo,
        ]);
        $bloque->id = $dia * 100 + (int) substr($inicio, 0, 2);

        return $bloque;
    }

    private function vista(array $consultorios): ConsultoriosVista
    {
        return new ConsultoriosVista(
            new Collection($consultorios),
            null,
            false,
            new Collection(),
            new Medico(['name' => 'Carlos', 'lastname' => 'Mendoza']),
        );
    }

    public function test_la_fila_trae_sus_bloques_de_lunes_a_domingo(): void
    {
        $vista = $this->vista([$this->consultorio('San José', '701', [
            $this->bloque(3, '14:00', '18:00', 12),
            $this->bloque(1, '08:00', '12:00'),
            $this->bloque(1, '13:00', '17:00'),
        ])]);

        $fila = $vista->filas()[0];

        // El orden es el de una semana (día y después hora), no el de la carga.
        $this->assertSame(
            [['Lunes', '08:00 a 12:00'], ['Lunes', '13:00 a 17:00'], ['Miércoles', '14:00 a 18:00']],
            array_map(fn ($bloque) => [$bloque['dia'], $bloque['horario']], $fila['bloques']),
        );
        $this->assertSame('12 pacientes', $fila['bloques'][2]['cupo']);
        // Sin cupo no se inventa un tope: la fila lo dice.
        $this->assertNull($fila['bloques'][0]['cupo']);
        // El identificador del consultorio viaja para el `consultorio` oculto del alta en línea.
        $this->assertSame(7, $fila['id']);
    }

    public function test_la_fila_trae_el_alta_de_un_bloque_con_sus_siete_dias(): void
    {
        $vista = $this->vista([$this->consultorio('San José', '701')]);

        $fila = $vista->filas()[0];

        $this->assertSame([], $fila['bloques']);
        $this->assertSame(
            ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
            array_column($fila['opcionesDeDia'], 'texto'),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($fila['opcionesDeDia'], 'valor'));
        // El alta arranca vacía y ningún día viene elegido.
        $this->assertSame(['dia_semana' => '', 'hora_inicio' => '', 'hora_fin' => '', 'cupo' => ''], $fila['alta']);
        $this->assertSame([false], array_values(array_unique(array_column($fila['opcionesDeDia'], 'elegido'))));
    }

    /** El borrado no se lleva citas: la confirmación nombra el bloque y dice qué deja de contar. */
    public function test_la_confirmacion_de_quitar_nombra_el_bloque_y_avisa_que_no_borra_citas(): void
    {
        $vista = $this->vista([$this->consultorio('San José', '701', [$this->bloque(2, '08:00', '12:00')])]);

        $confirmar = $vista->filas()[0]['bloques'][0]['confirmar'];

        $this->assertStringContainsString('Martes', $confirmar);
        $this->assertStringContainsString('08:00 a 12:00', $confirmar);
        $this->assertStringContainsString('no se borran', $confirmar);
    }

    public function test_la_duracion_vacia_se_muestra_como_raya(): void
    {
        $vista = $this->vista([$this->consultorio('San José', '701', [], null)]);

        $this->assertNull($vista->filas()[0]['duracion']);
    }

    /**
     * La ayuda de la duración es el otro pedido de A-10 («que el texto diga para qué sirve») y no puede
     * prometer lo que el código no hace: la jornada y su tope salen del cupo del bloque, no de este campo.
     */
    public function test_la_ayuda_de_la_duracion_dice_para_que_sirve_sin_prometer_el_tope(): void
    {
        $ayuda = $this->vista([])->ayudaDuracion();

        $this->assertStringContainsString('Lo que dura una cita en este consultorio', $ayuda);
        $this->assertStringContainsString('tope de pacientes de cada jornada lo pone el cupo', $ayuda);
    }
}
