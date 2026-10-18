<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que el bot le ofrece a la paciente cuando hay lugar (ajuste del 2026-10-18):
 *
 *  - **varios días** (hasta tres) en vez de uno solo, y
 *  - **sin decirle cuántos cupos quedan** (es información interna del consultorio).
 *
 * Y que se pueda elegir uno de esos días por número o por nombre, para que la oferta no sea decorativa.
 */
class ChatbotDisponibilidadTest extends TestCase
{
    use DatabaseTransactions;

    /** Un lunes a las 09:00, para que "los próximos días" no dependa del día real. */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ofrece_hasta_tres_dias_y_no_dice_cuantos_cupos_quedan(): void
    {
        $medico = $this->medico();
        $disponibilidad = $this->contexto()->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($disponibilidad['disponible']);
        $this->assertCount(3, $disponibilidad['opciones'], 'Sin sedes configuradas igual hay que ofrecer más de un día.');
        $this->assertSame('2026-10-19', $disponibilidad['opciones'][0]['fecha']);
        $this->assertSame('2026-10-20', $disponibilidad['opciones'][1]['fecha']);
        $this->assertSame('2026-10-21', $disponibilidad['opciones'][2]['fecha']);

        $texto = $this->contexto()->textoDeOpcionesDeDisponibilidad($disponibilidad);

        $this->assertStringContainsString('Próximos días', $texto);
        $this->assertStringContainsString('Lunes 19 de octubre de 2026', $texto);
        $this->assertStringContainsString('Martes 20 de octubre de 2026', $texto);
        $this->assertStringContainsString('Miércoles 21 de octubre de 2026', $texto);
        $this->assertStringNotContainsString('Cupo', $texto);
        $this->assertStringNotContainsString('cupo', $texto);
    }

    /**
     * Un consultorio que sólo atiende los lunes ofrece **los próximos tres lunes**, no tres días seguidos:
     * se ofrecen días con lugar de verdad, no fechas de calendario que después no se pueden reservar.
     */
    public function test_ofrece_los_dias_que_de_verdad_atiende(): void
    {
        $medico = $this->medico();

        $sede = $this->sede($medico, Office::MODALIDAD_ORDEN);
        $this->bloque($sede, '08:00', '12:00', 1);

        $disponibilidad = $this->contexto()->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($disponibilidad['disponible']);
        $this->assertSame(
            ['2026-10-19', '2026-10-26', '2026-11-02'],
            array_column($disponibilidad['opciones'], 'fecha')
        );
    }

    /** Con una sola opción pedida, el mensaje se adapta (y tampoco habla de cupos). */
    public function test_con_una_sola_opcion_lo_ofrece_en_singular(): void
    {
        $medico = $this->medico();

        $sede = $this->sede($medico, Office::MODALIDAD_ORDEN);
        $this->bloque($sede, '08:00', '12:00', 1);

        $disponibilidad = $this->contexto()->consultarProximoDiaDisponible($medico->reg_medico, 30, 1);

        $this->assertTrue($disponibilidad['disponible']);
        $this->assertCount(1, $disponibilidad['opciones']);

        $texto = $this->contexto()->textoDeOpcionesDeDisponibilidad($disponibilidad);

        $this->assertStringContainsString('Próximo día con disponibilidad', $texto);
        $this->assertStringContainsString('Lunes 19 de octubre de 2026', $texto);
        $this->assertStringNotContainsString('Cupo', $texto);
    }

    /** La oferta es la misma que acepta la reserva: un día ofrecido siempre se puede reservar. */
    public function test_todos_los_dias_ofrecidos_se_pueden_reservar(): void
    {
        $medico = $this->medico();
        $disponibilidad = $this->contexto()->consultarProximoDiaDisponible($medico->reg_medico);

        // Una paciente distinta por día: la regla de la casa es una cita pendiente por paciente y médico.
        foreach ($disponibilidad['opciones'] as $indice => $opcion) {
            $paciente = $this->paciente('5841658004' . (10 + $indice));
            $resultado = $this->contexto()->reservarCitaEnCola($paciente->telefono, $medico->reg_medico, $opcion['fecha']);

            $this->assertTrue($resultado['exito'], "No se pudo reservar el día ofrecido {$opcion['fecha']}: " . ($resultado['mensaje'] ?? ''));

            $cita = Cola::where('paciente_sinhistoria_id', $paciente->id)->firstOrFail();
            $this->assertSame($opcion['fecha'], Carbon::parse($cita->fecha)->toDateString());
        }

        $this->assertSame(3, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_interpreta_el_numero_el_nombre_del_dia_y_el_primero(): void
    {
        $contexto = $this->contexto();

        $pendiente = ['fechas' => ['2026-10-19', '2026-10-20', '2026-10-21']];

        $this->assertSame('2026-10-19', $contexto->interpretarDiaElegido('1', $pendiente));
        $this->assertSame('2026-10-20', $contexto->interpretarDiaElegido('2', $pendiente));
        $this->assertSame('2026-10-21', $contexto->interpretarDiaElegido('la 3', $pendiente));
        $this->assertSame('2026-10-20', $contexto->interpretarDiaElegido('el martes', $pendiente));
        $this->assertSame('2026-10-21', $contexto->interpretarDiaElegido('¿puede ser el miércoles?', $pendiente));
        $this->assertSame('2026-10-19', $contexto->interpretarDiaElegido('el primero, mejor', $pendiente));

        // Lo que no elige un día deja que decida el resto del flujo.
        $this->assertNull($contexto->interpretarDiaElegido('sí, dale', $pendiente));
        $this->assertNull($contexto->interpretarDiaElegido('gracias', $pendiente));
        $this->assertNull($contexto->interpretarDiaElegido('el jueves', $pendiente));

        // Sin fechas guardadas no hay nada que interpretar (pendientes viejos, de una sola fecha).
        $this->assertNull($contexto->interpretarDiaElegido('2', ['fecha' => '2026-10-19']));
    }

    // ----------------------------------------------------------------------------------- Ayudas

    private function contexto(): PacienteAtencionContextService
    {
        return app(PacienteAtencionContextService::class);
    }

    private function medico(): Medico
    {
        $regMedico = 'disp-test-' . substr(uniqid(), -6);

        $medico = Medico::create([
            'name' => 'Dr. de prueba',
            'lastname' => 'Disponibilidad',
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function sede(Medico $medico, string $modalidad): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Centro ' . substr(uniqid(), -6),
            'address' => 'Dirección de prueba',
        ]);

        return Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => $medico->reg_medico,
            'office_number' => 'C-DISP',
            'modalidad' => $modalidad,
            'duracion_cita' => 30,
            'activo' => true,
        ]);
    }

    private function bloque(Office $sede, string $inicio, string $fin, ?int $cupo = null): OfficeSchedule
    {
        return OfficeSchedule::create([
            'office_id' => $sede->id,
            'reg_medico' => $sede->reg_medico,
            'dia_semana' => 1, // lunes
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
            'cupo' => $cupo,
        ]);
    }

    private function paciente(string $telefono): Paciente
    {
        return Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'cedula' => (string) random_int(10000000, 30000000),
            'telefono' => $telefono,
            'sexo' => 'F',
            'fnacimiento' => '1990-05-04',
        ]);
    }
}
